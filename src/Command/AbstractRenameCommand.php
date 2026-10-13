<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\Exception\AnalysisMemoryBudgetExceededException;
use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function sprintf;

/**
 * Bounded legacy workflow for hash, pattern, date and lowercase commands.
 * Owns duplicate grouping/naming and legacy execution while sharing the same
 * CLI lifecycle as EXIF. Simple commands retain their useful strategy path;
 * EXIF no longer needs their grouping dependency for its constructor.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
abstract class AbstractRenameCommand extends AbstractMediaRenameCommand
{
    /**
     * Adds legacy duplicate grouping to the shared CLI collaborators.
     *
     * @param FileSystemServiceInterface         $fileSystemService         Shared iteration and execution boundary
     * @param DuplicateDetectionServiceInterface $duplicateDetectionService Legacy grouping and duplicate naming
     * @param SafeRegex                          $safeRegex                 Safe source filtering
     * @param Filesystem                         $filesystem                Command-facing filesystem boundary
     */
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        protected DuplicateDetectionServiceInterface $duplicateDetectionService,
        SafeRegex $safeRegex,
        Filesystem $filesystem,
    ) {
        parent::__construct($fileSystemService, $safeRegex, $filesystem);
    }

    /**
     * Template method that runs the rename pipeline (scan, group, assign, execute).
     * Subclasses may override to add pre/post-processing steps (e.g. Live Photo pairing).
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function executeCommand(): int
    {
        try {
            $this->processAndRenameFiles();
        } catch (RuntimeException $exception) {
            $this->io->error(ConsoleText::neutralize($exception->getMessage()));

            return self::FAILURE;
        }

        $this->io->success('done');

        return self::SUCCESS;
    }

    /**
     * Executes the full pipeline: creates the file iterator, counts files, groups
     * them by duplicate identifier, assigns duplicate filenames and invokes the
     * file system service to perform the actual rename/copy operations.
     *
     * @throws AnalysisMemoryBudgetExceededException When retained files reach the pre-mutation budget
     */
    private function processAndRenameFiles(): void
    {
        // Single file mode: create a temp directory with a symlink so the pipeline
        // processes exactly one file while keeping the iterator type consistent.
        $iterator = $this->createFileIterator();

        $duplicates = $this->groupFilesByDuplicateIdentifier($iterator);

        $fileDuplicateCollection = $this->createDuplicateFilenames($duplicates);

        $result = new RenameResult(
            scannedFiles: $this->duplicateDetectionService->getLastScannedFileCount(),
            namingCollisions: $this->duplicateDetectionService->getNamingCollisions(),
            skippedFiles: $this->duplicateDetectionService->getSkippedFiles(),
            fallbackDateFiles: $this->duplicateDetectionService->getFallbackDateFiles(),
            ambiguousTimezoneFiles: $this->duplicateDetectionService->getAmbiguousTimezoneFiles(),
            livePhotoConflictFiles: $this->duplicateDetectionService->getLivePhotoConflictFiles(),
        );

        $this->renderPostScanSummary($result);

        $this->fileSystemService
            ->renameFiles(
                $fileDuplicateCollection,
                new RenameOptions(
                    dryRun: $this->dryRun,
                    listAll: $this->listAll,
                    sourceBaseDirectory: $this->sourceDirectory,
                    maxDateDrift: $this->maxDateDrift,
                    linkConfig: $this->linkConfig,
                ),
                $result,
                $this->showFilter,
            );

        $this->duplicateDetectionService->clearHashCache();
    }

    /**
     * Scans all files from the iterator, applies the rename strategy and groups
     * them by the duplicate identifier strategy. Subclasses may override to add
     * additional passes (e.g. Live Photo companion pairing).
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator Iterator yielding candidate files
     *
     * @return FileDuplicateCollection Grouped duplicate collection
     *
     * @throws AnalysisMemoryBudgetExceededException When collected files reach the pre-mutation budget
     */
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $this->io->text(sprintf('<fg=cyan>Scanning:</> %s', ConsoleText::escape($this->sourceDirectory)));

        // Process list of all files
        return $this->duplicateDetectionService
            ->groupFilesByDuplicateIdentifier(
                iterator: $iterator,
                renameStrategy: $this->getTargetFilenameStrategy(),
                duplicateIdentifierStrategy: $this->getDuplicateIdentifierStrategy(),
                sourceDirectory: $this->sourceDirectory,
            );
    }

    /**
     * Assigns sequential "-duplicate-NNN" filenames to all non-canonical files in each
     * group, applying hash sub-grouping when enabled. Preserves the iteration order
     * from the grouping phase.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Groups produced by the grouping phase
     *
     * @return FileDuplicateCollection Same collection with rename targets populated
     */
    private function createDuplicateFilenames(FileDuplicateCollection $fileDuplicateCollection): FileDuplicateCollection
    {
        $this->io->newLine();
        $this->io->text('<fg=cyan>Resolving duplicates</>');

        return $this->duplicateDetectionService
            ->createDuplicateFilenames(
                $fileDuplicateCollection,
                $this->sourceDirectory,
                $this->useFileExtensionFromSource,
                $this->skipHashSubGrouping(),
            );
    }

    /**
     * Returns whether content-hash sub-grouping should be skipped.
     *
     * Override in subclasses that already group by content hash (e.g. rename:hash).
     */
    protected function skipHashSubGrouping(): bool
    {
        return false;
    }

    /**
     * Returns the rename strategy that computes target filenames from source files.
     *
     * Each concrete command provides its own strategy (EXIF date, pattern, lowercase, etc.),
     * which defines the core renaming logic and determines the primary sort order
     * of the result list.
     */
    abstract protected function getTargetFilenameStrategy(): RenameStrategyInterface;

    /**
     * Returns the duplicate identifier strategy that determines how files are grouped.
     *
     * Each concrete command selects the grouping granularity (basename, filename,
     * pathname, or content-hash). This determines which files are considered
     * "versions" of each other and must receive duplicate suffixes.
     */
    abstract protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface;
}
