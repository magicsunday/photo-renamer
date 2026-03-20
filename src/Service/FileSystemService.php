<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use FilesystemIterator;
use MagicSunday\Renamer\Command\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_exists;
use function in_array;
use function is_dir;
use function mb_strlen;
use function mkdir;
use function rename;
use function rtrim;
use function sprintf;
use function strlen;

/**
 * Handles all direct file system interactions: creating file iterators, counting files,
 * executing the actual rename operations, and resolving runtime target collisions
 * via the {@see findAvailableDuplicateTarget()} fallback. Delegates output entry building
 * and summary rendering to {@see RenameOutputRenderer}. Tracks occupied paths in-memory
 * to prevent data loss during batch moves.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FileSystemService implements FileSystemServiceInterface
{
    /**
     * @param SymfonyStyle         $io       Console IO for progress bars, status output and error messages
     * @param RenameOutputRenderer $renderer Handles output entry building and summary rendering
     */
    public function __construct(
        private SymfonyStyle $io,
        private RenameOutputRenderer $renderer,
    ) {
    }

    /**
     * Creates an iterator for traversing files in the given directory.
     *
     * @param string                                      $directory         The directory that should be scanned
     * @param RecursiveIterator<string, SplFileInfo>|null $recursiveIterator Optional preconfigured iterator to use instead of instantiating a default one
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> Iterator yielding only leaf nodes (files)
     */
    #[Override]
    public function createFileIterator(
        string $directory,
        ?RecursiveIterator $recursiveIterator = null,
    ): RecursiveIteratorIterator {
        if (!$recursiveIterator instanceof RecursiveIterator) {
            $recursiveIterator = new RecursiveRegexFileFilterIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                ),
                '/^.+$/'
            );
        }

        return new RecursiveIteratorIterator(
            $recursiveIterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    /**
     * Collects all regular files from the given directory into a flat list.
     *
     * @param string $directory Absolute directory path to scan
     *
     * @return list<SplFileInfo> All files found in the directory tree
     */
    #[Override]
    public function collectFiles(string $directory): array
    {
        $iterator = $this->createFileIterator($directory);

        /** @var list<SplFileInfo> $files */
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Renames or copies files represented by the provided duplicate collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection describing source/target file pairs grouped by duplicate identifier
     * @param RenameOptions           $options                 Options controlling the rename operation
     * @param RenameResult            $result                  Pipeline-computed results (scanned files, collisions, skips)
     * @param list<string>|null       $showFilter              When set, only output entries matching these tags are shown
     */
    #[Override]
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
        RenameResult $result = new RenameResult(),
        ?array $showFilter = null,
    ): void {
        $sourceBaseDirectory = $this->normalizeBaseDirectory($options->sourceBaseDirectory);

        $livePhotoGroups = $this->renderer->countLivePhotoGroups($fileDuplicateCollection);
        $totalOperations = $this->renderer->countTotalOperations($fileDuplicateCollection);

        [$outputEntries, $maxFilenameLength, $skippedCount, $errorCount]
            = $this->renderer->buildOutputEntries($fileDuplicateCollection, $options, $result, $sourceBaseDirectory);

        $occupiedPaths = $this->buildOccupiedPaths($fileDuplicateCollection);

        $this->io->newLine();
        $this->io->text('<fg=cyan>Renaming files</>');
        $this->io->newLine();

        $counters = $this->renderOutputEntries($outputEntries, $maxFilenameLength, $options, $occupiedPaths, $showFilter);

        $this->renderer->renderSummary([
            'scannedFiles'     => $result->scannedFiles > 0 ? $result->scannedFiles : $totalOperations,
            'skippedCount'     => $skippedCount,
            'errorCount'       => $errorCount,
            'livePhotoGroups'  => $livePhotoGroups,
            'namingCollisions' => $result->namingCollisions,
            ...$counters,
        ], $options->dryRun);
    }

    /**
     * Builds an in-memory index of all occupied file paths for collision detection.
     *
     * @return array<string, true>
     */
    private function buildOccupiedPaths(
        FileDuplicateCollection $fileDuplicateCollection,
    ): array {
        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [];

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $occupiedPaths[$rename->getSource()->getPathname()] = true;
            }
        }

        return $occupiedPaths;
    }

    /**
     * Renders the filtered output entries and executes file operations.
     *
     * @param list<array<string, mixed>> $outputEntries
     * @param array<string, true>        $occupiedPaths
     * @param list<string>|null          $showFilter
     *
     * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int}
     */
    private function renderOutputEntries(
        array $outputEntries,
        int $maxFilenameLength,
        RenameOptions $options,
        array &$occupiedPaths,
        ?array $showFilter = null,
    ): array {
        $fileCount      = 0;
        $duplicateCount = 0;
        $plannedMoves   = 0;
        $plannedSkips   = 0;

        foreach ($outputEntries as $entry) {
            /** @var string $sourcePath */
            $sourcePath = $entry['sourcePath'];

            /** @var OutputEntryTag $entryTag */
            $entryTag = $entry['tag'];

            // Compensate for multi-byte characters: sprintf pads by byte length,
            // but the terminal aligns by display width (mb_strlen).
            $padWidth = $maxFilenameLength + strlen($sourcePath) - mb_strlen($sourcePath);

            $formatString = ' %s <fg=yellow>%-' . $padWidth . 's</> <fg=cyan>→</> %s';

            if ($entry['type'] === 'skip') {
                /** @var string $reason */
                $reason = $entry['reason'];

                if ($showFilter === null || in_array($entryTag->letter(), $showFilter, true)) {
                    $this->io->text(sprintf(
                        $formatString,
                        $entryTag->formattedTag(),
                        $sourcePath,
                        sprintf('<fg=%s>%s</>', $entryTag->color(), $reason),
                    ));
                }

                continue;
            }

            /** @var string $targetPath */
            $targetPath = $entry['targetPath'];

            /** @var bool $isDuplicateTarget */
            $isDuplicateTarget = $entry['isDuplicateTarget'];

            /** @var bool $shouldSkip */
            $shouldSkip = $entry['shouldSkip'];

            /** @var bool $shouldPerformOperation */
            $shouldPerformOperation = $entry['shouldPerformOperation'];

            /** @var Rename $rename */
            $rename = $entry['rename'];

            if ($showFilter === null || in_array($entryTag->letter(), $showFilter, true)) {
                $this->io->text(sprintf(
                    $formatString,
                    $entryTag->formattedTag(),
                    $sourcePath,
                    $this->renderer->highlightDiff($sourcePath, $targetPath, 'green'),
                ));

                if ($shouldSkip) {
                    $skipReason = match ($entryTag) {
                        OutputEntryTag::Fallback => 'fallback date',
                        OutputEntryTag::Warning  => 'suspicious date',
                        default                  => 'duplicate',
                    };
                    $this->io->text(sprintf('       <fg=red>⏭  Skipped (%s)</>', $skipReason));
                }
            }

            if ($isDuplicateTarget) {
                ++$duplicateCount;
            }

            if ($shouldSkip) {
                ++$plannedSkips;
            }

            if ($shouldPerformOperation) {
                ++$plannedMoves;
                ++$fileCount;

                if ($options->dryRun === false) {
                    $this->moveFile(
                        $rename->getSource(),
                        $rename->getTarget(),
                        $occupiedPaths,
                    );
                }
            }
        }

        return [
            'fileCount'      => $fileCount,
            'duplicateCount' => $duplicateCount,
            'plannedMoves'   => $plannedMoves,
            'plannedSkips'   => $plannedSkips,
        ];
    }

    /**
     * Strips trailing directory separators from a base directory string.
     * Returns null for null or empty inputs.
     *
     * @param string|null $baseDirectory Raw base directory path
     *
     * @return string|null Trimmed path, or null
     */
    private function normalizeBaseDirectory(?string $baseDirectory): ?string
    {
        if ($baseDirectory === null) {
            return null;
        }

        $normalized = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * Moves a single file from source to target, creating directories as needed.
     * When the target path is already occupied (by a file moved earlier in the same batch),
     * falls back to {@see findAvailableDuplicateTarget()} to prevent data loss. Updates the
     * occupied-paths index to reflect the new file system state.
     *
     * @param SplFileInfo         $sourceFileInfo Source file to move
     * @param SplFileInfo         $targetFileInfo Intended target path
     * @param array<string, true> $occupiedPaths  Mutable index of paths currently occupied on disk
     *
     * @throws RuntimeException When the directory cannot be created or the file operation fails
     */
    private function moveFile(
        SplFileInfo $sourceFileInfo,
        SplFileInfo $targetFileInfo,
        array &$occupiedPaths = [],
    ): void {
        $targetDirectory = $targetFileInfo->getPath();

        if (
            !file_exists($targetDirectory)
            && !mkdir($targetDirectory, 0755, true)
            && !is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $targetDirectory
                )
            );
        }

        $sourcePath = $sourceFileInfo->getPathname();
        $targetPath = $targetFileInfo->getPathname();

        if (!$sourceFileInfo->isFile()) {
            throw new RuntimeException(
                sprintf('Source file "%s" does not exist', $sourcePath),
            );
        }

        // Target already occupied by a different file (moved there earlier in the same batch).
        // Fall back to the next available duplicate suffix to prevent data loss.
        if (
            $targetPath !== $sourcePath
            && isset($occupiedPaths[$targetPath])
        ) {
            $targetFileInfo = $this->findAvailableDuplicateTarget($targetFileInfo, $occupiedPaths);
            $targetPath     = $targetFileInfo->getPathname();
        }

        $result = @rename($sourcePath, $targetPath);

        if ($result === false) {
            throw new RuntimeException(
                sprintf('Failed to move file to "%s"', $targetPath),
            );
        }

        // Move: source freed.
        unset($occupiedPaths[$sourcePath]);

        $occupiedPaths[$targetPath] = true;
    }

    /**
     * Finds the next available duplicate target path that is not occupied.
     *
     * @param array<string, true> $occupiedPaths current set of occupied paths
     */
    private function findAvailableDuplicateTarget(SplFileInfo $target, array $occupiedPaths): SplFileInfo
    {
        $ext      = $target->getExtension();
        $basename = FileHelper::basenameWithoutExtension($target);
        $dir      = $target->getPath();

        // Strip any existing duplicate suffix to avoid nested suffixes (e.g. -duplicate-003-duplicate-001).
        $basename = FileHelper::stripDuplicateSuffix($basename);

        $counter = 1;

        do {
            if ($counter > Constants::MAX_DUPLICATE_SUFFIX) {
                throw new RuntimeException(
                    sprintf('Exceeded %d attempts finding available target for "%s"', Constants::MAX_DUPLICATE_SUFFIX, $basename)
                );
            }

            $candidatePath = sprintf(
                '%s%s%s%s%03d.%s',
                $dir,
                DIRECTORY_SEPARATOR,
                $basename,
                Constants::DUPLICATE_IDENTIFIER,
                $counter,
                $ext,
            );

            ++$counter;
        } while (isset($occupiedPaths[$candidatePath]));

        return new SplFileInfo($candidatePath);
    }
}
