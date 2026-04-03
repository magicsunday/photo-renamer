<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Output\OutputCounters;
use Override;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * Handles command-facing file system interactions while delegating narrower
 * responsibilities to specialized collaborators.
 *
 * The facade keeps the legacy command wiring stable, while:
 * - {@see FileCollector} owns directory traversal
 * - {@see RuntimeCollisionPathAllocator} owns runtime duplicate-suffix fallback
 * - {@see RuntimeFileMoveExecutor} owns concrete runtime move/fallback mechanics
 * - {@see ExecutionPlanExecutor} owns the ExecutionPlan runtime execution path
 * - {@see RenameOutputRenderer} owns output projection and presentation
 *
 * The remaining responsibility of this class is orchestrating physical file
 * mutation and occupied-path tracking around those collaborators.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FileSystemService implements FileSystemServiceInterface
{
    private RuntimeFileMoveExecutor $runtimeFileMoveExecutor;

    private ExecutionPlanExecutor $executionPlanExecutor;

    /**
     * @param SymfonyStyle                  $io                            Console IO for progress bars, status output and error messages
     * @param RenameOutputRenderer          $renderer                      Handles output entry building and summary rendering
     * @param Filesystem                    $filesystem                    Symfony Filesystem for file operations
     * @param FileCollector                 $fileCollector                 Collects files and creates iterators for directory scans
     * @param RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator Allocates duplicate-suffix fallback paths during runtime collisions
     * @param RuntimeFileMoveExecutor|null  $runtimeFileMoveExecutor       Performs concrete runtime moves with duplicate-suffix fallback handling
     * @param ExecutionPlanExecutor|null    $executionPlanExecutor         Executes the runtime ExecutionPlan path behind the stable facade
     */
    public function __construct(
        private SymfonyStyle $io,
        private RenameOutputRenderer $renderer,
        private Filesystem $filesystem = new Filesystem(),
        private FileCollector $fileCollector = new FileCollector(),
        private RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator = new RuntimeCollisionPathAllocator(),
        ?RuntimeFileMoveExecutor $runtimeFileMoveExecutor = null,
        ?ExecutionPlanExecutor $executionPlanExecutor = null,
    ) {
        $this->runtimeFileMoveExecutor = $runtimeFileMoveExecutor
            ?? new RuntimeFileMoveExecutor($this->io, $this->filesystem, $this->runtimeCollisionPathAllocator);
        $this->executionPlanExecutor = $executionPlanExecutor
            ?? new ExecutionPlanExecutor($this->io, $this->runtimeFileMoveExecutor);
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
        return $this->fileCollector->createFileIterator($directory, $recursiveIterator);
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
        return $this->fileCollector->collectFiles($directory);
    }

    /**
     * Renames or copies files represented by the provided duplicate collection.
     *
     * This is the main entry point for the "legacy" rename flow (not using
     * the ExecutionPlan). It handles building output entries, rendering
     * progress, and performing the actual move operations.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection The collection of planned renames.
     * @param RenameOptions           $options                 Configuration for the rename run.
     * @param RenameResult            $result                  Aggregate results from the pipeline.
     * @param list<string>|null       $showFilter              Optional tag filter for console output.
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

        [$outputEntries, $skippedCount, $errorCount]
            = $this->renderer->buildOutputEntries($fileDuplicateCollection, $options, $result, $sourceBaseDirectory);

        $occupiedPaths = $this->buildOccupiedPaths($fileDuplicateCollection);

        $this->io->newLine();
        $this->io->text('<fg=cyan>Renaming files</>');
        $this->io->newLine();

        $counters = $this->renderOutputEntries($outputEntries, $options, $occupiedPaths, $sourceBaseDirectory, $showFilter);

        $this->renderer->renderSummary([
            'scannedFiles'     => $result->scannedFiles > 0 ? $result->scannedFiles : $totalOperations,
            'skippedCount'     => $skippedCount,
            'errorCount'       => $errorCount,
            'livePhotoGroups'  => $livePhotoGroups,
            'namingCollisions' => $result->namingCollisions,
            ...$counters->toArray(),
        ], $options->dryRun);
    }

    /**
     * Executes a runtime plan, performing the actual file rename operations.
     *
     * Builds an occupied-path index from the plan and uses runtime collision
     * fallback as a safety layer to ensure no files are overwritten even if
     * the plan had a logic error.
     *
     * @param ExecutionPlan $plan   The runtime execution plan to process.
     * @param bool          $dryRun When true, simulate without touching the filesystem.
     *
     * @return ExecutionResult Runtime execution counters (moves, fallbacks, errors).
     */
    #[Override]
    public function executePlan(ExecutionPlan $plan, bool $dryRun = false): ExecutionResult
    {
        return $this->executionPlanExecutor->executePlan($plan, $dryRun);
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
     * @param list<OutputEntry>   $outputEntries
     * @param array<string, true> $occupiedPaths
     * @param list<string>|null   $showFilter
     *
     * @return OutputCounters Immutable counters already computed by the shared renderer
     */
    private function renderOutputEntries(
        array $outputEntries,
        RenameOptions $options,
        array &$occupiedPaths,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
    ): OutputCounters {
        $counters = $this->renderer->renderEntryLines($outputEntries, $sourceBaseDirectory, $showFilter);

        foreach ($outputEntries as $entry) {
            if (!$entry->isRename()) {
                continue;
            }

            // sortKey carries the absolute source path; reconstruct absolute
            // target by prepending the base directory when paths were relativized.
            $absoluteSource = $entry->sortKey;
            $absoluteTarget = ($sourceBaseDirectory !== null && ($entry->targetPath !== null))
                ? $sourceBaseDirectory . DIRECTORY_SEPARATOR . $entry->targetPath
                : ($entry->targetPath ?? $absoluteSource);

            if ($entry->shouldSkip) {
                // Skipped file stays at its source path — keep it occupied so
                // other files do not try to rename into it. Also mark the
                // source path as occupied in case it was freed by a prior move.
                $occupiedPaths[$absoluteSource] = true;
            } elseif ($entry->shouldPerformOperation) {
                $this->moveFile(
                    new SplFileInfo($absoluteSource),
                    new SplFileInfo($absoluteTarget),
                    $occupiedPaths,
                    $options->dryRun,
                );
            }
        }

        return $counters;
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
     * Delegates to {@see moveFileByPath()} for the actual move logic.
     *
     * @param SplFileInfo         $sourceFileInfo Source file to move
     * @param SplFileInfo         $targetFileInfo Intended target path
     * @param array<string, true> $occupiedPaths  Mutable index of paths currently occupied on disk
     * @param bool                $dryRun         When true, track path changes without touching the filesystem
     *
     * @throws RuntimeException When the directory cannot be created or the file operation fails
     */
    private function moveFile(
        SplFileInfo $sourceFileInfo,
        SplFileInfo $targetFileInfo,
        array &$occupiedPaths = [],
        bool $dryRun = false,
    ): void {
        $this->runtimeFileMoveExecutor->moveFileByPath(
            $sourceFileInfo->getPathname(),
            $targetFileInfo->getPathname(),
            $occupiedPaths,
            $dryRun,
        );
    }
}
