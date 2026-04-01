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
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\LinkConfig;
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
use Symfony\Component\Filesystem\Filesystem;

use function basename;
use function dirname;
use function in_array;
use function mb_strlen;
use function pathinfo;
use function rtrim;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_BASENAME;
use const PATHINFO_EXTENSION;

/**
 * Handles all direct file system interactions: creating file iterators, counting files,
 * executing the actual rename operations, and resolving runtime target collisions
 * via the {@see findAvailableDuplicatePath()} fallback. Delegates output entry building
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
     * @param SymfonyStyle         $io         Console IO for progress bars, status output and error messages
     * @param RenameOutputRenderer $renderer   Handles output entry building and summary rendering
     * @param Filesystem           $filesystem Symfony Filesystem for file operations
     */
    public function __construct(
        private SymfonyStyle $io,
        private RenameOutputRenderer $renderer,
        private Filesystem $filesystem = new Filesystem(),
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
            ...$counters,
        ], $options->dryRun);
    }

    /**
     * Execute a runtime plan, performing the actual file rename operations.
     * Builds an occupied-path index from the plan and uses runtime collision
     * fallback as a safety layer.
     *
     * @param ExecutionPlan $plan   The runtime execution plan
     * @param bool          $dryRun When true, simulate without touching filesystem
     *
     * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int}
     */
    #[Override]
    public function executePlan(ExecutionPlan $plan, bool $dryRun = false): array
    {
        $occupiedPaths = $this->buildOccupiedPathsFromPlan($plan);

        $fileCount      = 0;
        $duplicateCount = 0;
        $plannedMoves   = 0;
        $plannedSkips   = 0;

        foreach ($plan->groups as $group) {
            foreach ($group->items as $item) {
                if (!$item->isExecutable) {
                    // Non-executable item: keep source path occupied so other files
                    // don't try to move into it. Count as planned skip if it has
                    // a block reason (not just a no-op).
                    $occupiedPaths[$item->sourcePath] = true;

                    if ($item->executionBlockReason !== null) {
                        ++$plannedSkips;
                    }

                    continue;
                }

                if ($item->isDuplicateTarget) {
                    ++$duplicateCount;
                }

                // Execute the move using shared helper
                try {
                    $this->moveFileByPath(
                        $item->sourcePath,
                        $item->targetPath,
                        $occupiedPaths,
                        $dryRun,
                    );

                    ++$fileCount;
                    ++$plannedMoves;
                } catch (RuntimeException $exception) {
                    // Keep source occupied to prevent collisions with remaining items
                    $occupiedPaths[$item->sourcePath] = true;
                    $this->io->error(sprintf('Failed to rename %s: %s', $item->sourcePath, $exception->getMessage()));
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
     * @param list<array<string, mixed>> $outputEntries
     * @param array<string, true>        $occupiedPaths
     * @param list<string>|null          $showFilter
     *
     * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int}
     */
    private function renderOutputEntries(
        array $outputEntries,
        RenameOptions $options,
        array &$occupiedPaths,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
    ): array {
        // Compute max path length only over visible entries so padding is tight
        $maxFilenameLength = 0;

        foreach ($outputEntries as $entry) {
            /** @var OutputEntryTag $entryTag */
            $entryTag = $entry['tag'];

            if (!$this->isTagVisible($entryTag, $showFilter)) {
                continue;
            }

            /** @var string $sourcePath */
            $sourcePath        = $entry['sourcePath'];
            $maxFilenameLength = max($maxFilenameLength, mb_strlen($sourcePath));
        }

        $linkConfig = LinkConfig::fromEnv();

        $fileCount      = 0;
        $duplicateCount = 0;
        $plannedMoves   = 0;
        $plannedSkips   = 0;

        foreach ($outputEntries as $entry) {
            /** @var string $sourcePath */
            $sourcePath = $entry['sourcePath'];

            /** @var OutputEntryTag $entryTag */
            $entryTag = $entry['tag'];

            $padding    = str_repeat(' ', max(0, $maxFilenameLength - mb_strlen($sourcePath)));
            $linkedPath = FileHelper::linkifyPath($sourcePath, $sourcePath, $sourceBaseDirectory, $linkConfig, 'yellow');

            if ($entry['type'] === 'skip') {
                /** @var string $reason */
                $reason = $entry['reason'];

                if ($this->isTagVisible($entryTag, $showFilter)) {
                    $this->io->text(sprintf(
                        ' %s %s' . $padding . ' <fg=cyan>→</> <fg=%s>%s</>',
                        $entryTag->formattedTag(),
                        $linkedPath,
                        $entryTag->color(),
                        $reason,
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

            if ($this->isTagVisible($entryTag, $showFilter)) {
                if ($shouldSkip) {
                    /** @var string|null $warningReason */
                    $warningReason = $entry['warningReason'] ?? null;

                    $skipReason = match ($entryTag) {
                        OutputEntryTag::Candidate => 'Conflicting Live Photo content ID across groups',
                        OutputEntryTag::Warning   => $warningReason ?? 'Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone',
                        OutputEntryTag::Fallback  => 'Fallback date: DateTime (0x0132) used instead of DateTimeOriginal',
                        default                   => 'Skipped',
                    };

                    $this->io->text(sprintf(
                        ' %s %s' . $padding . ' <fg=cyan>→</> <fg=%s>%s</>',
                        $entryTag->formattedTag(),
                        $linkedPath,
                        $entryTag->color(),
                        $skipReason,
                    ));
                } else {
                    $this->io->text(sprintf(
                        ' %s %s' . $padding . ' <fg=cyan>→</> %s',
                        $entryTag->formattedTag(),
                        $linkedPath,
                        $this->renderer->highlightDiff($sourcePath, $targetPath, 'green'),
                    ));
                }
            }

            if ($isDuplicateTarget) {
                ++$duplicateCount;
            }

            if ($shouldSkip) {
                ++$plannedSkips;
            }

            if ($shouldSkip) {
                // Skipped file stays at its source path — keep it occupied so
                // other files do not try to rename into it. Also mark the
                // source path as occupied in case it was freed by a prior move.
                $occupiedPaths[$rename->getSource()->getPathname()] = true;
            } elseif ($shouldPerformOperation) {
                ++$plannedMoves;
                ++$fileCount;

                $this->moveFile(
                    $rename->getSource(),
                    $rename->getTarget(),
                    $occupiedPaths,
                    $options->dryRun,
                );
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
     * {@see findAvailableDuplicatePath()} to prevent data loss. Updates the occupied-paths
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
            $targetPath = $this->findAvailableDuplicatePath($targetPath, $occupiedPaths);
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

    /**
     * Finds the next available duplicate target path that is not occupied.
     *
     * Strips any existing duplicate suffix from the target basename to avoid nested
     * suffixes (e.g. "-duplicate-003-duplicate-001"), then increments a counter until
     * an unoccupied candidate path is found.
     *
     * @param string              $targetPath    Absolute target file path
     * @param array<string, true> $occupiedPaths Current set of occupied paths
     *
     * @return string An available absolute path with a duplicate suffix appended
     *
     * @throws RuntimeException When the maximum duplicate suffix count is exceeded
     */
    private function findAvailableDuplicatePath(string $targetPath, array $occupiedPaths): string
    {
        $ext      = pathinfo($targetPath, PATHINFO_EXTENSION);
        $dir      = dirname($targetPath);
        $basename = pathinfo($targetPath, PATHINFO_BASENAME);

        // Strip the extension from basename to get the stem.
        if ($ext !== '') {
            $basename = substr($basename, 0, -(strlen($ext) + 1));
        }

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

        return $candidatePath;
    }

    /**
     * Checks whether the given tag passes the optional display filter.
     *
     * @param OutputEntryTag    $tag        Tag to check
     * @param list<string>|null $showFilter Allowed tag letters, or null to show all
     */
    private function isTagVisible(OutputEntryTag $tag, ?array $showFilter): bool
    {
        return ($showFilter === null) || in_array($tag->letter(), $showFilter, true);
    }
}
