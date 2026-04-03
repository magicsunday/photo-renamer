<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionPreview;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_key_exists;
use function count;
use function in_array;
use function is_string;
use function max;
use function mb_str_split;
use function mb_stripos;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function ucfirst;
use function usort;

/**
 * Handles all console output rendering for the rename phase.
 *
 * This class centralizes all display logic, including:
 * - Building merged and sorted output entry lists from rename operations.
 * - Rendering summary statistics tables with aligned columns.
 * - Managing different output tags (Canonical, Duplicate, Warning, etc.).
 * - Highlighting differences between source and target filenames for readability.
 * - Providing display-related query helpers (e.g., counting Live Photo groups).
 *
 * It was extracted from {@see FileSystemService} to strictly separate rendering
 * concerns from physical file I/O operations, making the display logic more
 * testable and maintainable.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameOutputRenderer
{
    /**
     * @param SymfonyStyle $io Symfony Style IO for consistent console output formatting
     */
    public function __construct(private SymfonyStyle $io)
    {
    }

    /**
     * Builds a merged, path-sorted list of all output entries from rename operations
     * and skipped files for display during the rename phase.
     *
     * This method processes the result of the rename operation and prepares a list
     * of {@see OutputEntry} objects. It handles the identification of duplicate
     * targets, no-op operations (where source equals target), and canonical entries.
     * The final list is sorted by source pathname to provide a predictable and
     * clean overview in the console.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection The collection of identified duplicates
     *                                                         and their planned rename operations.
     * @param RenameOptions           $options                 Configuration options controlling the rename
     *                                                         behavior (e.g., whether to list all files).
     * @param RenameResult            $result                  The global result object containing metadata
     *                                                         quality flags, collisions, and error states.
     * @param string|null             $sourceBaseDirectory     The normalized base directory used to relativize
     *                                                         absolute paths for cleaner display.
     *
     * @return array{list<OutputEntry>, int, int} A tuple containing the list of entries, the count of
     *                                            skipped files, and the count of files with errors.
     */
    public function buildOutputEntries(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options,
        RenameResult $result,
        ?string $sourceBaseDirectory,
    ): array {
        /** @var list<OutputEntry> $outputEntries */
        $outputEntries = [];

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            $canonicalTargetPath = $fileDuplicate->getTarget()->getPathname();
            $canonicalBasename   = FileHelper::basenameWithoutExtension($fileDuplicate->getTarget());

            foreach ($fileDuplicate->getRenames() as $rename) {
                $renameBasename = FileHelper::basenameWithoutExtension($rename->getTarget());
                // A file is a duplicate if its target basename differs from the canonical
                // and contains the duplicate identifier. Only actual renames are tagged [D];
                // no-op duplicates (already correctly named) fall through to [O].
                $isDuplicateTarget = ($renameBasename !== $canonicalBasename)
                    && str_contains($renameBasename, Constants::DUPLICATE_IDENTIFIER);

                $isNoOp           = $rename->getSource()->getPathname() === $rename->getTarget()->getPathname();
                $isCanonicalEntry = ($renameBasename === $canonicalBasename)
                    && ($isNoOp || ($options->listAll && ($rename->getSource()->getPathname() === $canonicalTargetPath)));

                $sourcePath = FileHelper::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);
                $targetPath = FileHelper::relativizePath($rename->getTarget()->getPathname(), $sourceBaseDirectory);

                $sourcePathname = $rename->getSource()->getPathname();

                $tag = $this->resolveEntryTag(
                    $sourcePathname,
                    $isDuplicateTarget,
                    $isNoOp,
                    $isCanonicalEntry,
                    $result,
                );

                [$tag, $warningReason]                 = $this->applyDateDriftCheck($tag, null, $sourcePath, $targetPath, $options);
                [$shouldSkip, $shouldPerformOperation] = $this->computeSkipFlags($tag, $isNoOp);

                $outputEntries[] = OutputEntry::rename(
                    sortKey: $rename->getSource()->getPathname(),
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    tag: $tag,
                    isDuplicateTarget: $isDuplicateTarget,
                    shouldSkip: $shouldSkip,
                    shouldPerformOperation: $shouldPerformOperation,
                    warningReason: $warningReason,
                );

                // Append info line showing which file this is a duplicate of
                if (($tag === OutputEntryTag::Duplicate) && !$isNoOp) {
                    $outputEntries[] = OutputEntry::info(
                        sortKey: $rename->getSource()->getPathname(),
                        sourcePath: $sourcePath,
                        reason: sprintf(
                            'Duplicate of %s',
                            FileHelper::relativizePath($canonicalTargetPath, $sourceBaseDirectory),
                        ),
                        tag: OutputEntryTag::Duplicate,
                    );
                }
            }
        }

        [$skippedCount, $errorCount] = $this->appendSkippedFileEntries($outputEntries, $result, $sourceBaseDirectory);

        usort($outputEntries, static function (OutputEntry $entryA, OutputEntry $entryB): int {
            $cmp = $entryA->sortKey <=> $entryB->sortKey;

            return $cmp !== 0 ? $cmp : ($entryA->type->sortOrder() <=> $entryB->type->sortOrder());
        });

        return [$outputEntries, $skippedCount, $errorCount];
    }

    /**
     * Resolves the output entry tag for a file based on its metadata quality,
     * duplicate status, and canonical position.
     *
     * The tag represents the status of the file in the rename process and determines
     * the prefix (e.g., [O], [D], [W]) shown in the console output.
     *
     * Priority chain (top to bottom):
     * 1. [C] Candidate: Live Photo identifier conflict (requires manual resolution).
     * 2. [W] Warning: Ambiguous timezone data.
     * 3. [F] Fallback: Date extracted from secondary metadata fields.
     * 4. [D] Duplicate: File identified as a duplicate (contains identifier suffix).
     * 5. [O] Original: The canonical entry of a group or a file where no rename is needed.
     * 6. [R] Rename: A standard rename operation.
     *
     * Note: [O] wins for no-ops (source === target) to signal that no physical
     * file operation will be performed.
     *
     * @param string       $sourcePathname    The absolute path of the source file.
     * @param bool         $isDuplicateTarget True if the target filename indicates a duplicate
     *                                        (contains the duplicate identifier).
     * @param bool         $isNoOp            True if the source and target paths are identical.
     * @param bool         $isCanonicalEntry  True if this file is the primary/canonical item
     *                                        within its asset group.
     * @param RenameResult $result            The global result object carrying metadata flags.
     *
     * @return OutputEntryTag The resolved tag determining the entry's display status.
     */
    private function resolveEntryTag(
        string $sourcePathname,
        bool $isDuplicateTarget,
        bool $isNoOp,
        bool $isCanonicalEntry,
        RenameResult $result,
    ): OutputEntryTag {
        if (isset($result->livePhotoConflictFiles[$sourcePathname])) {
            return OutputEntryTag::Candidate;
        }

        if (isset($result->ambiguousTimezoneFiles[$sourcePathname]) && !$isNoOp) {
            return OutputEntryTag::Warning;
        }

        if (isset($result->fallbackDateFiles[$sourcePathname]) && !$isNoOp) {
            return OutputEntryTag::Fallback;
        }

        if ($isDuplicateTarget && !$isNoOp) {
            return OutputEntryTag::Duplicate;
        }

        if ($isCanonicalEntry || $isNoOp) {
            return OutputEntryTag::Original;
        }

        return OutputEntryTag::Rename;
    }

    /**
     * Renders an aligned two-column table of label/value pairs.
     *
     * This method calculates the maximum width of both columns to ensure perfect
     * alignment in the console output. It is primarily used for the summary
     * statistics at the end of a command.
     *
     * @param list<array{string, string}> $rows The list of label/value pairs to display.
     * @param SymfonyStyle|null           $io   Optional console IO. If null, the instance's
     *                                          default IO is used.
     */
    public function renderAlignedTable(array $rows, ?SymfonyStyle $io = null): void
    {
        $io ??= $this->io;

        $maxLabelLength = 0;
        $maxValueLength = 0;

        foreach ($rows as $row) {
            $maxLabelLength = max($maxLabelLength, strlen($row[0]));
            $maxValueLength = max($maxValueLength, strlen($row[1]));
        }

        foreach ($rows as $row) {
            $io->text(sprintf(
                ' %-' . $maxLabelLength . 's  %' . $maxValueLength . 's',
                $row[0],
                $row[1],
            ));
        }
    }

    /**
     * Renders a summary section with a header, an aligned table, and a trailing newline.
     *
     * This provides a consistent visual style for summary reports across all
     * available commands.
     *
     * @param list<array{string, string}> $rows The list of label/value pairs to display in the table.
     * @param SymfonyStyle|null           $io   Optional console IO. If null, the instance's
     *                                          default IO is used.
     */
    public function renderSummarySection(array $rows, ?SymfonyStyle $io = null): void
    {
        $io ??= $this->io;

        $io->text('<fg=cyan>Summary</>');
        $io->newLine();

        $this->renderAlignedTable($rows, $io);

        $io->newLine();
    }

    /**
     * Renders the summary table with file counts and statistics.
     *
     * @param array<string, int> $counters
     */
    public function renderSummary(array $counters, bool $dryRun): void
    {
        $this->io->newLine();

        $rows = [
            ['Scanned files', (string) $counters['scannedFiles']],
        ];

        if ($counters['skippedCount'] > 0) {
            $rows[] = ['Skipped (no metadata)', (string) $counters['skippedCount']];
        }

        if ($counters['errorCount'] > 0) {
            $rows[] = ['Skipped (read errors)', (string) $counters['errorCount']];
        }

        if ($counters['plannedMoves'] > 0) {
            $rows[] = ['Planned moves', (string) $counters['plannedMoves']];
        }

        if ($counters['plannedSkips'] > 0) {
            $rows[] = ['Planned skips', (string) $counters['plannedSkips']];
        }

        if ($counters['livePhotoGroups'] > 0) {
            $rows[] = ['Live Photo groups', (string) $counters['livePhotoGroups']];
        }

        if ($counters['duplicateCount'] > 0) {
            $rows[] = ['Duplicates found', (string) $counters['duplicateCount']];
        }

        if ($counters['namingCollisions'] > 0) {
            $rows[] = ['Naming collisions', (string) $counters['namingCollisions']];
        }

        if (array_key_exists('crossGroupVideoReviewCount', $counters) && ($counters['crossGroupVideoReviewCount'] > 0)) {
            $rows[] = ['Cross-group video review', (string) $counters['crossGroupVideoReviewCount']];
        }

        $rows[] = [$dryRun ? 'Files to process' : 'Files processed', (string) $counters['fileCount']];

        $this->renderSummarySection($rows);
    }

    /**
     * Renders the decision log from an AssetGroupCollection.
     * Shows per-group reasoning for canonical selection and companion detection.
     *
     * @param AssetGroupCollection $groups The groups with populated decision logs
     */
    public function renderDecisionLog(
        AssetGroupCollection $groups,
    ): void {
        $hasAnyLog = false;

        foreach ($groups as $group) {
            $log = $group->getDecisionLog();

            if ($log === []) {
                continue;
            }

            if (!$hasAnyLog) {
                $this->io->newLine();
                $this->io->text('<fg=cyan>Decision Log</>');
                $hasAnyLog = true;
            }

            $this->io->text(sprintf('  <fg=yellow>%s</>:', $group->groupKey));

            foreach ($log as $entry) {
                $this->io->text('    ' . $entry);
            }
        }

        if ($hasAnyLog) {
            $this->io->newLine();
        }
    }

    // ---------------------------------------------------------------
    //  ExecutionPlan rendering path
    // ---------------------------------------------------------------

    /**
     * Builds a merged, path-sorted list of output entries from an ExecutionPlan
     * and skipped files from the RenameResult. Produces the same output entry
     * array structure as {@see buildOutputEntries()} so both paths share the
     * rendering and summary logic.
     *
     * @param ExecutionPlan $plan                The runtime execution plan
     * @param RenameOptions $options             Command options (skip flags, date drift, etc.)
     * @param RenameResult  $result              Scan/analysis summary data (skipped files)
     * @param string|null   $sourceBaseDirectory Base directory for relative path display
     *
     * @return array{list<OutputEntry>, int, int} Tuple of [entries, skippedCount, errorCount]
     */
    public function buildOutputEntriesFromPlan(
        ExecutionPlan $plan,
        RenameOptions $options,
        RenameResult $result,
        ?string $sourceBaseDirectory = null,
    ): array {
        /** @var list<OutputEntry> $outputEntries */
        $outputEntries = [];

        foreach ($plan->groups as $group) {
            // Find the canonical target path for "Duplicate of" info lines
            $canonicalTargetPath = null;

            foreach ($group->items as $item) {
                if ($item->type === ExecutionItemType::Canonical) {
                    $canonicalTargetPath = $item->targetPath;

                    break;
                }
            }

            foreach ($group->items as $item) {
                $sourcePath = FileHelper::relativizePath($item->sourcePath, $sourceBaseDirectory);
                $targetPath = FileHelper::relativizePath($item->targetPath, $sourceBaseDirectory);

                $tag = $this->resolveItemTag($item);

                [$tag, $warningReason] = $this->applyDateDriftCheck($tag, $item->warningReason ?? $item->executionBlockReason, $sourcePath, $targetPath, $options);

                // Use isExecutable from the ExecutionItem to determine skip status.
                // Date drift (applied above) can also trigger a skip via the tag.
                $shouldSkip = (!$item->isExecutable && !$item->isNoOp)
                    || $tag->isScanningSkip()
                    || ($tag === OutputEntryTag::Warning)
                    || ($tag === OutputEntryTag::Candidate);
                $shouldPerformOperation = !$shouldSkip && !$item->isNoOp;

                $outputEntries[] = OutputEntry::rename(
                    sortKey: $item->sourcePath,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    tag: $tag,
                    isDuplicateTarget: $item->isDuplicateTarget,
                    shouldSkip: $shouldSkip,
                    shouldPerformOperation: $shouldPerformOperation,
                    warningReason: $warningReason,
                );

                // Append info line showing which file this is a duplicate of
                if (($tag === OutputEntryTag::Duplicate) && !$item->isNoOp && ($canonicalTargetPath !== null)) {
                    $outputEntries[] = OutputEntry::info(
                        sortKey: $item->sourcePath,
                        sourcePath: $sourcePath,
                        reason: sprintf(
                            'Duplicate of %s',
                            FileHelper::relativizePath($canonicalTargetPath, $sourceBaseDirectory),
                        ),
                        tag: OutputEntryTag::Duplicate,
                    );
                }
            }
        }

        [$skippedCount, $errorCount] = $this->appendSkippedFileEntries($outputEntries, $result, $sourceBaseDirectory);

        usort($outputEntries, static function (OutputEntry $entryA, OutputEntry $entryB): int {
            $cmp = $entryA->sortKey <=> $entryB->sortKey;

            return $cmp !== 0 ? $cmp : ($entryA->type->sortOrder() <=> $entryB->type->sortOrder());
        });

        return [$outputEntries, $skippedCount, $errorCount];
    }

    /**
     * Renders the output entries for an ExecutionPlan to the console.
     * Does NOT execute file operations — that is the responsibility of
     * {@see FileSystemService::executePlan()}.
     *
     * @param ExecutionPlan     $plan                The runtime execution plan
     * @param RenameOptions     $options             Command options (skip flags, date drift, etc.)
     * @param string|null       $sourceBaseDirectory Base directory for relative path display
     * @param list<string>|null $showFilter          Tag filter (null = show all)
     * @param RenameResult|null $result              Pipeline results supplying [S]/[E] skipped entries (null = empty)
     *
     * @return ExecutionPreview Plan-time counts (planned moves, skips, duplicates)
     */
    public function renderPlanEntries(
        ExecutionPlan $plan,
        RenameOptions $options,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
        ?RenameResult $result = null,
    ): ExecutionPreview {
        [$outputEntries] = $this->buildOutputEntriesFromPlan(
            $plan,
            $options,
            $result ?? new RenameResult(),
            $sourceBaseDirectory,
        );

        $counters = $this->renderEntryLines($outputEntries, $sourceBaseDirectory, $showFilter);

        return new ExecutionPreview(
            plannedMoves: $counters['plannedMoves'],
            plannedSkips: $counters['plannedSkips'],
            duplicateCount: $counters['duplicateCount'],
        );
    }

    /**
     * Renders the summary section combining ExecutionPlan counters with
     * RenameResult analysis data. Delegates to the shared
     * {@see renderSummary()} method.
     *
     * @param ExecutionPlan    $plan    The execution plan
     * @param RenameResult     $result  Scan/analysis summary data
     * @param ExecutionPreview $preview Plan-time counts from renderPlanEntries()
     * @param bool             $dryRun  Whether the run is dry-run mode
     */
    public function renderPlanSummary(
        ExecutionPlan $plan,
        RenameResult $result,
        ExecutionPreview $preview,
        bool $dryRun,
    ): void {
        $skippedCount = 0;
        $errorCount   = 0;

        foreach ($result->skippedFiles as $skippedFile) {
            if ($skippedFile->isError()) {
                ++$errorCount;
            } else {
                ++$skippedCount;
            }
        }

        $this->renderSummary([
            'scannedFiles'               => $result->scannedFiles,
            'skippedCount'               => $skippedCount,
            'errorCount'                 => $errorCount,
            'livePhotoGroups'            => $plan->livePhotoGroupCount(),
            'namingCollisions'           => $result->namingCollisions,
            'crossGroupVideoReviewCount' => $result->crossGroupVideoReviewCount,
            'fileCount'                  => $preview->plannedMoves,
            'duplicateCount'             => $preview->duplicateCount,
            'plannedMoves'               => $preview->plannedMoves,
            'plannedSkips'               => $preview->plannedSkips,
        ], $dryRun);
    }

    /**
     * Returns the number of groups in the plan that contain Live Photo companions.
     *
     * @param ExecutionPlan $plan The execution plan to inspect
     *
     * @return int Number of Live Photo groups
     */
    public function countLivePhotoGroupsInPlan(ExecutionPlan $plan): int
    {
        return $plan->livePhotoGroupCount();
    }

    /**
     * Renders the decision log from an ExecutionPlan's groups.
     * Shows per-group reasoning for canonical selection and companion detection.
     *
     * @param ExecutionPlan $plan The plan with populated decision logs
     */
    public function renderDecisionLogFromPlan(ExecutionPlan $plan): void
    {
        $hasAnyLog = false;

        foreach ($plan->groups as $group) {
            if ($group->decisionLog === []) {
                continue;
            }

            if (!$hasAnyLog) {
                $this->io->newLine();
                $this->io->text('<fg=cyan>Decision Log</>');
                $hasAnyLog = true;
            }

            $this->io->text(sprintf('  <fg=yellow>%s</>:', $group->groupKey));

            foreach ($group->decisionLog as $entry) {
                $this->io->text('    ' . $entry);
            }
        }

        if ($hasAnyLog) {
            $this->io->newLine();
        }
    }

    /**
     * Applies date drift checking and default warning reason to a tag.
     * Returns the (possibly upgraded) tag and the warning reason.
     *
     * @param OutputEntryTag $tag           Current tag before drift check
     * @param string|null    $warningReason Existing warning reason (null for legacy path)
     * @param string         $sourcePath    Relative source path for date extraction
     * @param string         $targetPath    Relative target path for date extraction
     * @param RenameOptions  $options       Command options (maxDateDrift)
     *
     * @return array{OutputEntryTag, string|null} Adjusted [tag, warningReason]
     */
    private function applyDateDriftCheck(
        OutputEntryTag $tag,
        ?string $warningReason,
        string $sourcePath,
        string $targetPath,
        RenameOptions $options,
    ): array {
        if (($tag === OutputEntryTag::Warning) && ($warningReason === null)) {
            $warningReason = 'Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone';
        }

        if (
            (($tag === OutputEntryTag::Rename) || ($tag === OutputEntryTag::Fallback))
            && ($options->maxDateDrift !== null)
            && ($options->maxDateDrift > 0)
        ) {
            $driftDays = FileHelper::computeDateDrift($sourcePath, $targetPath);

            if (($driftDays !== null) && ($driftDays > $options->maxDateDrift)) {
                $tag           = OutputEntryTag::Warning;
                $warningReason = sprintf(
                    'Date drift: %d days between filename and metadata (max %d) — verify EXIF date or use rename:write-date',
                    $driftDays,
                    $options->maxDateDrift,
                );
            }
        }

        return [$tag, $warningReason];
    }

    /**
     * Computes the shouldSkip and shouldPerformOperation flags from
     * the resolved tag. [W] (ambiguous timezone) and [C] (LP conflict)
     * items are always skipped.
     *
     * @param OutputEntryTag $tag    The resolved output entry tag
     * @param bool           $isNoOp Whether source === target (no rename needed)
     *
     * @return array{bool, bool} [shouldSkip, shouldPerformOperation]
     */
    private function computeSkipFlags(
        OutputEntryTag $tag,
        bool $isNoOp,
    ): array {
        $shouldSkip = ($tag === OutputEntryTag::Candidate)
            || ($tag === OutputEntryTag::Warning);

        $shouldPerformOperation = ($shouldSkip === false) && (!$isNoOp);

        return [$shouldSkip, $shouldPerformOperation];
    }

    /**
     * Appends skipped file entries from the RenameResult to the output entries array.
     * Returns the counts of skipped (no-metadata) and error entries.
     *
     * @param list<OutputEntry> $outputEntries       Output entries array (modified by reference)
     * @param RenameResult      $result              Result carrying skipped files
     * @param string|null       $sourceBaseDirectory Base directory for path relativization
     *
     * @return array{int, int} [skippedCount, errorCount]
     */
    private function appendSkippedFileEntries(
        array &$outputEntries,
        RenameResult $result,
        ?string $sourceBaseDirectory,
    ): array {
        $skippedCount = 0;
        $errorCount   = 0;

        foreach ($result->skippedFiles as $skippedFile) {
            if ($skippedFile->isError()) {
                ++$errorCount;
            } else {
                ++$skippedCount;
            }

            $outputEntries[] = OutputEntry::skip(
                sortKey: $skippedFile->getFile()->getPathname(),
                sourcePath: FileHelper::relativizePath($skippedFile->getFile()->getPathname(), $sourceBaseDirectory),
                reason: ucfirst($skippedFile->getReason()),
                tag: $skippedFile->isError() ? OutputEntryTag::Error : OutputEntryTag::Skipped,
            );
        }

        foreach ($result->crossDirectoryCompanions as [$canonicalPath, $companionPath]) {
            $relativeCanonicalPath = FileHelper::relativizePath($canonicalPath, $sourceBaseDirectory);

            $outputEntries[] = OutputEntry::info(
                sortKey: $companionPath,
                sourcePath: FileHelper::relativizePath($companionPath, $sourceBaseDirectory),
                reason: sprintf(
                    'Live Photo pair across directories: <fg=cyan>%s</>',
                    $relativeCanonicalPath,
                ),
            );
        }

        foreach ($result->reviewEntries as $reviewEntry) {
            $outputEntries[] = $reviewEntry;
        }

        return [$skippedCount, $errorCount];
    }

    /**
     * Renders a list of output entries to the console and returns counters.
     * Used by both the ExecutionPlan and legacy rendering paths.
     *
     * @param list<OutputEntry> $outputEntries       Sorted output entries
     * @param string|null       $sourceBaseDirectory Base directory for linkified paths
     * @param list<string>|null $showFilter          Tag filter (null = show all)
     *
     * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int}
     */
    public function renderEntryLines(
        array $outputEntries,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
    ): array {
        // Compute max path length only over visible entries so padding is tight
        $maxFilenameLength = 0;

        foreach ($outputEntries as $entry) {
            if (!$this->isTagVisible($entry->tag, $showFilter)) {
                continue;
            }

            $maxFilenameLength = max($maxFilenameLength, mb_strlen($entry->sourcePath));
        }

        $linkConfig = LinkConfig::fromEnv();

        $fileCount           = 0;
        $duplicateCount      = 0;
        $plannedMoves        = 0;
        $plannedSkips        = 0;
        $lastRenderedSortKey = null;

        foreach ($outputEntries as $entry) {
            $padding    = str_repeat(' ', max(0, $maxFilenameLength - mb_strlen($entry->sourcePath)));
            $linkedPath = FileHelper::linkifyPath($entry->sourcePath, $entry->sourcePath, $sourceBaseDirectory, $linkConfig, 'yellow');

            if ($entry->isInfo()) {
                if (!$this->isTagVisible($entry->tag, $showFilter)) {
                    continue;
                }

                if ($lastRenderedSortKey === $entry->sortKey) {
                    // Render as continuation line under the previous entry.
                    $this->io->text(sprintf(
                        '     <fg=cyan>→</> <fg=%s>%s</>',
                        $entry->tag->color(),
                        $entry->reason ?? '',
                    ));
                } else {
                    // No visible anchor line was rendered for this sort key, so show a
                    // standalone two-line block with the path on the first line.
                    $this->renderTwoLineReasonBlock($entry->tag, $linkedPath, $entry->reason ?? '');
                }

                $lastRenderedSortKey = $entry->sortKey;

                continue;
            }

            if ($entry->isSkip()) {
                if ($this->isTagVisible($entry->tag, $showFilter)) {
                    $this->renderTwoLineReasonBlock($entry->tag, $linkedPath, $entry->reason ?? '');

                    $lastRenderedSortKey = $entry->sortKey;
                }

                continue;
            }

            // Rename entry (Structural type: Rename)
            if ($this->isTagVisible($entry->tag, $showFilter)) {
                if ($entry->shouldSkip) {
                    $skipReason = match ($entry->tag) {
                        OutputEntryTag::Candidate => 'Conflicting Live Photo content ID across groups',
                        OutputEntryTag::Review    => $entry->reason ?? 'Cross-group video review required',
                        OutputEntryTag::Warning   => $entry->warningReason ?? 'Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone',
                        OutputEntryTag::Fallback  => 'Fallback date: DateTime (0x0132) used instead of DateTimeOriginal',
                        default                   => 'Skipped',
                    };

                    $this->renderTwoLineReasonBlock($entry->tag, $linkedPath, $skipReason);
                } else {
                    $this->io->text(sprintf(
                        ' %s %s' . $padding . ' <fg=cyan>→</> %s',
                        $entry->tag->formattedTag(),
                        $linkedPath,
                        $this->highlightDiff($entry->sourcePath, $entry->targetPath ?? '', 'green'),
                    ));
                }

                $lastRenderedSortKey = $entry->sortKey;
            }

            if ($entry->isDuplicateTarget) {
                ++$duplicateCount;
            }

            if ($entry->shouldSkip) {
                ++$plannedSkips;
            } elseif ($entry->shouldPerformOperation) {
                ++$plannedMoves;
                ++$fileCount;
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
     * Resolves the output entry tag for an ExecutionItem based on its quality flags.
     *
     * Priority chain (top to bottom): Skipped > [C] > [W] > [F] > [D] > [O] > [R].
     * Exception: [O] wins for no-ops (!isNoOp guard on [W], [F], [D]).
     *
     * @param ExecutionItem $item The execution item to classify
     *
     * @return OutputEntryTag The resolved tag
     */
    private function resolveItemTag(ExecutionItem $item): OutputEntryTag
    {
        if ($item->type === ExecutionItemType::Skipped) {
            return OutputEntryTag::Skipped;
        }

        if ($item->isLivePhotoConflict) {
            return OutputEntryTag::Candidate;
        }

        if ($item->isAmbiguousTimezone && !$item->isNoOp) {
            return OutputEntryTag::Warning;
        }

        if ($item->isFallbackDate && !$item->isNoOp) {
            return OutputEntryTag::Fallback;
        }

        if ($item->isDuplicateTarget && !$item->isNoOp) {
            return OutputEntryTag::Duplicate;
        }

        if ($item->isNoOp) {
            return OutputEntryTag::Original;
        }

        return OutputEntryTag::Rename;
    }

    /**
     * Checks whether a tag should be displayed given the current show filter.
     *
     * @param OutputEntryTag    $tag        The tag to check
     * @param list<string>|null $showFilter Tag letters to display (null = show all)
     *
     * @return bool True when the tag should be rendered
     */
    private function isTagVisible(OutputEntryTag $tag, ?array $showFilter): bool
    {
        return ($showFilter === null) || in_array($tag->letter(), $showFilter, true);
    }

    /**
     * Renders a two-line output block with the tagged source path on the first line
     * and a colored reason on the second line.
     *
     * This layout keeps long warning/notice texts from pushing the source path out of
     * view and matches the two-line style already used by rename:dedup.
     *
     * @param OutputEntryTag $tag        Visual tag of the rendered entry
     * @param string         $linkedPath Source path, already linkified for console output
     * @param string         $reason     Human-readable explanation shown on the second line
     */
    private function renderTwoLineReasonBlock(OutputEntryTag $tag, string $linkedPath, string $reason): void
    {
        $this->io->text(sprintf(
            ' %s %s',
            $tag->formattedTag(),
            $linkedPath,
        ));
        $this->io->text(sprintf(
            '     <fg=cyan>→</> <fg=%s>%s</>',
            $tag->color(),
            $reason,
        ));
    }

    /**
     * Checks whether a duplicate identifier string uses the "live-photo:" prefix,
     * indicating the group was formed from Apple Live Photo content identifiers.
     *
     * @param int|string $duplicateIdentifier Group key to inspect
     *
     * @return bool True when the identifier starts with "live-photo:"
     */
    public function isLivePhotoIdentifier(int|string $duplicateIdentifier): bool
    {
        if (!is_string($duplicateIdentifier)) {
            return false;
        }

        return str_starts_with($duplicateIdentifier, Constants::LIVE_PHOTO_IDENTIFIER_PREFIX);
    }

    /**
     * Counts the total number of Live Photo groups in the collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection to inspect
     *
     * @return int Number of groups with a "live-photo:" prefix identifier
     */
    public function countLivePhotoGroups(FileDuplicateCollection $fileDuplicateCollection): int
    {
        $livePhotoGroups = 0;

        foreach ($fileDuplicateCollection as $duplicateIdentifier => $fileDuplicate) {
            if ($this->isLivePhotoIdentifier($duplicateIdentifier)) {
                ++$livePhotoGroups;
            }
        }

        return $livePhotoGroups;
    }

    /**
     * Calculates the total number of rename operations planned in the collection.
     *
     * Used for summary statistics to show how many individual file movements are expected.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection to count operations in
     *
     * @return int Total number of renames
     */
    public function countTotalOperations(FileDuplicateCollection $fileDuplicateCollection): int
    {
        $totalOperations = 0;

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            $totalOperations += count($fileDuplicate->getRenames());
        }

        return $totalOperations;
    }

    /**
     * Highlights the differences between source and target paths using color-coded output.
     *
     * It splits the path into directory and filename to avoid highlighting common parent
     * directories. If the directory differs, the entire path is highlighted.
     * Uses a sequential token matching algorithm for robust highlighting.
     *
     * @param string $source    The original path
     * @param string $target    The new path to highlight
     * @param string $baseColor The base color for unchanged parts (e.g., 'gray' or 'white')
     *
     * @return string ANSI-highlighted target path
     */
    public function highlightDiff(string $source, string $target, string $baseColor): string
    {
        if ($source === $target) {
            return sprintf('<fg=%s>%s</>', $baseColor, $target);
        }

        [$sourcePrefix, $sourceFilename] = $this->splitPathPrefix($source);
        [$targetPrefix, $targetFilename] = $this->splitPathPrefix($target);

        // If the directory part already differs, render the complete target path
        // using sequential token matching.
        if ($sourcePrefix !== $targetPrefix) {
            return $this->highlightSequentialTokenDiff($source, $target, $baseColor);
        }

        return sprintf('<fg=%s>%s</>', $baseColor, $targetPrefix)
            . $this->highlightSequentialTokenDiff($sourceFilename, $targetFilename, $baseColor);
    }

    /**
     * Splits a path into directory prefix and filename.
     *
     * The prefix includes the trailing slash or backslash when present.
     *
     * @return array{string, string}
     */
    private function splitPathPrefix(string $path): array
    {
        $slashPos     = strrpos($path, '/');
        $backslashPos = strrpos($path, '\\');

        $lastSlashPos = max(
            $slashPos === false ? -1 : $slashPos,
            $backslashPos === false ? -1 : $backslashPos,
        );

        if ($lastSlashPos < 0) {
            return ['', $path];
        }

        return [
            substr($path, 0, $lastSlashPos + 1),
            substr($path, $lastSlashPos + 1),
        ];
    }

    /**
     * Highlights the target string by matching its tokens sequentially against
     * the source string from left to right.
     *
     * This method implements a specialized diff visualization for filenames.
     * Instead of a standard character-based LCS (Longest Common Subsequence)
     * which can produce fragmented highlights for dates and counters, this
     * approach tokenizes the target and tries to find each token in the source,
     * maintaining a forward-only matching offset.
     *
     * Resulting states per token:
     * - 'same': Exact character match found at or after current offset.
     * - 'case-changed': Case-insensitive match found (e.g., '.JPG' vs '.jpg').
     * - 'changed': No match found; token is considered new/changed.
     *
     * @param string $source    The original filename for comparison
     * @param string $target    The new filename to highlight
     * @param string $baseColor ANSI color for unchanged segments
     *
     * @return string ANSI-highlighted string
     */
    private function highlightSequentialTokenDiff(string $source, string $target, string $baseColor): string
    {
        $tokens = $this->tokenizeForSequentialDiff($target);
        $flags  = $this->matchTargetTokensSequentially($source, $tokens);

        return $this->renderHighlightedTokens($tokens, $flags, $baseColor);
    }

    /**
     * Tokenizes a string into alphanumeric runs and separator runs.
     *
     * Examples:
     * - "2015-07-31_06-42-43-000.avi"
     *   => ["2015", "-", "07", "-", "31", "_", "06", "-", "42", "-", "43", "-", "000", ".", "avi"]
     *
     * @return list<string>
     */
    private function tokenizeForSequentialDiff(string $value): array
    {
        preg_match_all('/[[:alnum:]]+|[^[:alnum:]]/u', $value, $matches);

        /** @var list<string> $tokens */
        $tokens = $matches[0];

        return $tokens;
    }

    /**
     * Matches target tokens against the source string to determine their diff state.
     *
     * Iterates through the provided tokens and attempts to locate them in the
     * source string, starting from the last successful match position. This
     * ensures a stable, forward-moving match that reflects the structural
     * changes in a filename (e.g. prepending a date or adding a suffix).
     *
     * @param string       $source The original string to match against
     * @param list<string> $tokens Tokenized target string
     *
     * @return list<string> List of states ('same', 'case-changed', 'changed') for each token
     */
    private function matchTargetTokensSequentially(string $source, array $tokens): array
    {
        $states      = [];
        $sourceChars = mb_str_split($source);
        $sourceLen   = count($sourceChars);
        $offset      = 0;

        foreach ($tokens as $token) {
            if ($this->isSeparatorToken($token)) {
                $matched = $this->matchSeparatorNearOffset($sourceChars, $sourceLen, $token, $offset);

                $states[] = $matched ? 'same' : 'changed';

                if ($matched) {
                    $offset += mb_strlen($token);
                }

                continue;
            }

            $position = $this->findTokenPosition($source, $token, $offset);

            if ($position === null) {
                $states[] = 'changed';

                continue;
            }

            $sourceToken = mb_substr($source, $position, mb_strlen($token));

            if ($sourceToken === $token) {
                $states[] = 'same';
            } elseif (mb_strtolower($sourceToken) === mb_strtolower($token)) {
                $states[] = 'case-changed';
            } else {
                $states[] = 'changed';
            }

            $offset = $position + mb_strlen($token);
        }

        return $states;
    }

    /**
     * Finds an alphanumeric token in the source string starting at the given offset.
     *
     * Matching is case-insensitive for pure alphabetic or alphanumeric words such
     * as file extensions ("avi" vs "AVI", "mp4" vs "MP4").
     *
     * @return int|null Character offset or null when not found
     */
    private function findTokenPosition(string $source, string $token, int $offset): ?int
    {
        $position = mb_stripos($source, $token, $offset);

        if ($position === false) {
            return null;
        }

        return $position;
    }

    /**
     * Attempts to match a separator token near the current source offset.
     *
     * Separators (non-alphanumeric characters) are handled with a very small
     * lookahead window (1 character). This prevents a single added character
     * from breaking the alignment of all subsequent separators while avoiding
     * "false positive" matches from separators found much further ahead in
     * the string.
     *
     * @param list<string> $sourceChars Multibyte character array of the source string
     * @param int          $sourceLen   Total number of characters in the source
     * @param string       $token       The separator token to match
     * @param int          $offset      Current character offset in the source
     *
     * @return bool True if a match was found within the lookahead window
     */
    private function matchSeparatorNearOffset(array $sourceChars, int $sourceLen, string $token, int $offset): bool
    {
        $tokenChars = mb_str_split($token);
        $tokenLen   = count($tokenChars);

        for ($lookahead = 0; $lookahead <= 1; ++$lookahead) {
            $matched = true;

            for ($i = 0; $i < $tokenLen; ++$i) {
                $sourceIndex = $offset + $lookahead + $i;

                if (($sourceIndex >= $sourceLen) || ($sourceChars[$sourceIndex] !== $tokenChars[$i])) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the token contains only non-alphanumeric characters.
     */
    private function isSeparatorToken(string $token): bool
    {
        return preg_match('/^[^[:alnum:]]+$/u', $token) === 1;
    }

    /**
     * Renders the tokenized target string with ANSI color codes based on match states.
     *
     * Adjacent tokens with the same state are buffered and rendered as a single
     * ANSI segment to minimize the length of the resulting string and improve
     * terminal performance.
     *
     * @param list<string> $tokens    The original tokens
     * @param list<string> $states    Calculated states ('same', 'case-changed', 'changed')
     * @param string       $baseColor The color to use for 'same' segments
     *
     * @return string ANSI-formatted string
     */
    private function renderHighlightedTokens(array $tokens, array $states, string $baseColor): string
    {
        $result       = '';
        $buffer       = '';
        $currentState = null;

        foreach ($tokens as $index => $token) {
            $state = $states[$index];

            if (($currentState !== null) && ($state !== $currentState) && ($buffer !== '')) {
                $result .= $this->formatDiffSegment($buffer, $currentState, $baseColor);
                $buffer = '';
            }

            $buffer .= $token;
            $currentState = $state;
        }

        if (($buffer !== '') && ($currentState !== null)) {
            $result .= $this->formatDiffSegment($buffer, $currentState, $baseColor);
        }

        return $result;
    }

    /**
     * Formats a single segment of the diff with appropriate ANSI colors and options.
     *
     * - 'same': Rendered in base color.
     * - 'case-changed': Rendered in bright base color + bold.
     * - 'changed' (default): Rendered in bright base color + bold.
     *
     * @param string $value     The text segment to format
     * @param string $state     The match state ('same', 'case-changed', or default)
     * @param string $baseColor The base color name (e.g. 'green', 'gray')
     *
     * @return string The ANSI-formatted segment
     */
    private function formatDiffSegment(string $value, string $state, string $baseColor): string
    {
        return match ($state) {
            'same'         => sprintf('<fg=%s>%s</>', $baseColor, $value),
            'case-changed' => sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $value),
            default        => sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $value),
        };
    }
}
