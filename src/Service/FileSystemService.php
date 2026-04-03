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
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Output\OutputCounters;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function basename;
use function dirname;
use function rtrim;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Handles all direct file system interactions: creating file iterators, counting files,
 * executing the actual rename operations, and resolving runtime target collisions
 * via {@see RuntimeCollisionPathAllocator}. Delegates output entry building
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
     * @param SymfonyStyle                  $io                            Console IO for progress bars, status output and error messages
     * @param RenameOutputRenderer          $renderer                      Handles output entry building and summary rendering
     * @param Filesystem                    $filesystem                    Symfony Filesystem for file operations
     * @param RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator Allocates duplicate-suffix fallback paths during runtime collisions
     */
    public function __construct(
        private SymfonyStyle $io,
        private RenameOutputRenderer $renderer,
        private Filesystem $filesystem = new Filesystem(),
        private RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator = new RuntimeCollisionPathAllocator(),
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
        $occupiedPaths = $this->buildOccupiedPathsFromPlan($plan);

        $executedMoves    = 0;
        $runtimeFallbacks = 0;
        $runtimeErrors    = 0;

        foreach ($plan->groups as $group) {
            foreach ($group->items as $item) {
                if (!$item->isExecutable) {
                    // Non-executable item: keep source path occupied so other files
                    // don't try to move into it.
                    $occupiedPaths[$item->sourcePath] = true;

                    continue;
                }

                // Execute the move using shared helper
                try {
                    $actualTarget = $this->moveFileByPath(
                        $item->sourcePath,
                        $item->targetPath,
                        $occupiedPaths,
                        $dryRun,
                    );

                    if (!$dryRun) {
                        ++$executedMoves;

                        if ($actualTarget !== $item->targetPath) {
                            ++$runtimeFallbacks;
                        }
                    }
                } catch (RuntimeException $exception) {
                    // Keep source occupied to prevent collisions with remaining items
                    $occupiedPaths[$item->sourcePath] = true;
                    $this->io->error(sprintf('Failed to rename %s: %s', $item->sourcePath, $exception->getMessage()));

                    if (!$dryRun) {
                        ++$runtimeErrors;
                    }
                }
            }
        }

        return new ExecutionResult(
            executedMoves: $executedMoves,
            runtimeFallbacks: $runtimeFallbacks,
            runtimeErrors: $runtimeErrors,
        );
    }

    /**
     * Builds an occupied-path index from all source paths in an ExecutionPlan.
     *
     * @return array<string, true>
     */
    private function buildOccupiedPathsFromPlan(ExecutionPlan $plan): array
    {
        $occupiedPaths = [];

        foreach ($plan->groups as $group) {
            foreach ($group->items as $item) {
                $occupiedPaths[$item->sourcePath] = true;
            }
        }

        return $occupiedPaths;
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
        $this->moveFileByPath(
            $sourceFileInfo->getPathname(),
            $targetFileInfo->getPathname(),
            $occupiedPaths,
            $dryRun,
        );
    }

    /**
     * Moves a file from source path to target path, creating directories as needed.
     * When the target path is already occupied (by a file moved earlier in the same batch,
     * or a skipped file that stays at its source path), falls back to
     * {@see RuntimeCollisionPathAllocator::findAvailableDuplicatePath()} to prevent
     * data loss. Updates the occupied-paths
     * index to reflect the new file system state.
     *
     * Returns the actual target path used, which may differ from the requested target
     * if a duplicate-suffix fallback was applied.
     *
     * @param string              $sourcePath    Absolute source file path
     * @param string              $targetPath    Intended absolute target file path
     * @param array<string, true> $occupiedPaths Mutable index of paths currently occupied on disk
     * @param bool                $dryRun        When true, track path changes without touching the filesystem
     *
     * @return string The actual target path the file was moved to (may include duplicate suffix)
     *
     * @throws RuntimeException When the source file does not exist or the file operation fails
     */
    private function moveFileByPath(
        string $sourcePath,
        string $targetPath,
        array &$occupiedPaths,
        bool $dryRun,
    ): string {
        $plannedTarget = $targetPath;

        // Target already occupied by a different file (moved there earlier in
        // the same batch, or a skipped file that stays at its source path).
        // Fall back to the next available duplicate suffix to prevent data loss.
        if (
            ($targetPath !== $sourcePath)
            && isset($occupiedPaths[$targetPath])
        ) {
            $targetPath = $this->runtimeCollisionPathAllocator->findAvailableDuplicatePath($targetPath, $occupiedPaths);
        }

        if ($targetPath !== $plannedTarget) {
            $this->io->warning(sprintf(
                'Runtime collision fallback: %s → %s (planned: %s)',
                basename($sourcePath),
                basename($targetPath),
                basename($plannedTarget),
            ));
        }

        if (!$dryRun) {
            $sourceFileInfo = new SplFileInfo($sourcePath);

            if (!$sourceFileInfo->isFile()) {
                throw new RuntimeException(
                    sprintf('Source file "%s" does not exist', $sourcePath),
                );
            }

            $this->filesystem->mkdir(dirname($targetPath));
            $this->filesystem->rename($sourcePath, $targetPath);
        }

        // Track path changes even in dry-run to keep occupiedPaths consistent.
        unset($occupiedPaths[$sourcePath]);

        $occupiedPaths[$targetPath] = true;

        return $targetPath;
    }
}
