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
use MagicSunday\Renamer\Helper\DateDriftCalculator;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionPreview;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\Output\DiffHighlighter;
use MagicSunday\Renamer\Service\Output\OutputCounters;
use MagicSunday\Renamer\Service\Output\OutputDecisionLogRenderer;
use MagicSunday\Renamer\Service\Output\OutputEntryPresenter;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecider;
use MagicSunday\Renamer\Service\Output\OutputSummaryRowBuilder;
use MagicSunday\Renamer\Service\Output\SkipReasonFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_string;
use function pathinfo;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function ucfirst;
use function usort;

use const PATHINFO_EXTENSION;

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
    private OutputSkipReasonDecider $skipReasonDecider;

    private SkipReasonFormatter $skipReasonFormatter;

    private DiffHighlighter $diffHighlighter;

    private OutputDecisionLogRenderer $decisionLogRenderer;

    private OutputEntryPresenter $entryPresenter;

    private OutputSummaryRowBuilder $summaryRowBuilder;

    /**
     * @param SymfonyStyle $io Symfony Style IO for consistent console output formatting
     */
    public function __construct(private SymfonyStyle $io)
    {
        $this->skipReasonDecider   = new OutputSkipReasonDecider();
        $this->skipReasonFormatter = new SkipReasonFormatter();
        $this->diffHighlighter     = new DiffHighlighter();
        $this->decisionLogRenderer = new OutputDecisionLogRenderer();
        $this->entryPresenter      = new OutputEntryPresenter(
            $this->skipReasonDecider,
            $this->skipReasonFormatter,
            $this->diffHighlighter,
        );
        $this->summaryRowBuilder = new OutputSummaryRowBuilder();
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
            $canonicalTargetPath   = $fileDuplicate->getTarget()->getPathname();
            $canonicalBasename     = FileHelper::basenameWithoutExtension($fileDuplicate->getTarget());
            $referenceTargetsByExt = $this->buildReferenceTargetsByExtension($fileDuplicate->getRenames());

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

                $sourcePath = PathHelper::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);
                $targetPath = PathHelper::relativizePath($rename->getTarget()->getPathname(), $sourceBaseDirectory);

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
                            $this->resolveDuplicateReferenceTargetPath(
                                $targetPath,
                                $canonicalTargetPath,
                                $referenceTargetsByExt,
                                $sourceBaseDirectory,
                            ),
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

        $this->renderSummarySection($this->summaryRowBuilder->build($counters, $dryRun));
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
        $this->decisionLogRenderer->renderAssetGroupDecisionLog($groups, $this->io);
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
            $canonicalTargetPath   = null;
            $referenceTargetsByExt = [];

            foreach ($group->items as $item) {
                if (($item->type === ExecutionItemType::Canonical) && ($canonicalTargetPath === null)) {
                    $canonicalTargetPath = $item->targetPath;
                }

                if ($item->isDuplicateTarget) {
                    continue;
                }

                $normalizedExtension = FileHelper::normalizeExtension(
                    pathinfo($item->targetPath, PATHINFO_EXTENSION),
                );

                if ($normalizedExtension === '') {
                    continue;
                }

                $referenceTargetsByExt[$normalizedExtension] ??= $item->targetPath;
            }

            foreach ($group->items as $item) {
                $sourcePath = PathHelper::relativizePath($item->sourcePath, $sourceBaseDirectory);
                $targetPath = PathHelper::relativizePath($item->targetPath, $sourceBaseDirectory);

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
                            $this->resolveDuplicateReferenceTargetPath(
                                $item->targetPath,
                                $canonicalTargetPath,
                                $referenceTargetsByExt,
                                $sourceBaseDirectory,
                            ),
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
            plannedMoves: $counters->plannedMoves,
            plannedSkips: $counters->plannedSkips,
            duplicateCount: $counters->duplicateCount,
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
        $this->decisionLogRenderer->renderExecutionPlanDecisionLog($plan, $this->io);
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
            $driftDays = DateDriftCalculator::computeDateDrift($sourcePath, $targetPath);

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
                sourcePath: PathHelper::relativizePath($skippedFile->getFile()->getPathname(), $sourceBaseDirectory),
                reason: ucfirst($skippedFile->getReason()),
                tag: $skippedFile->isError() ? OutputEntryTag::Error : OutputEntryTag::Skipped,
            );
        }

        foreach ($result->crossDirectoryCompanions as [$canonicalPath, $companionPath]) {
            $relativeCanonicalPath = PathHelper::relativizePath($canonicalPath, $sourceBaseDirectory);

            $outputEntries[] = OutputEntry::info(
                sortKey: $companionPath,
                sourcePath: PathHelper::relativizePath($companionPath, $sourceBaseDirectory),
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
     * @return OutputCounters Immutable render counters shared with legacy execution
     */
    public function renderEntryLines(
        array $outputEntries,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
    ): OutputCounters {
        return $this->entryPresenter->render(
            $outputEntries,
            $this->io,
            $sourceBaseDirectory,
            $showFilter,
        );
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
     * Builds the first non-duplicate target path per extension for a duplicate group.
     *
     * This lets duplicate MOV explanations point at the matching non-duplicate MOV
     * target instead of always falling back to the canonical still target.
     *
     * @param iterable<Rename> $renames Planned renames inside one duplicate group
     *
     * @return array<string, string> First non-duplicate target path per normalized extension
     */
    private function buildReferenceTargetsByExtension(iterable $renames): array
    {
        $referenceTargetsByExt = [];

        foreach ($renames as $rename) {
            $renameBasename = FileHelper::basenameWithoutExtension($rename->getTarget());

            if (str_contains($renameBasename, Constants::DUPLICATE_IDENTIFIER)) {
                continue;
            }

            $normalizedExtension = FileHelper::normalizeExtension(
                pathinfo($rename->getTarget()->getPathname(), PATHINFO_EXTENSION),
            );

            if ($normalizedExtension === '') {
                continue;
            }

            $referenceTargetsByExt[$normalizedExtension] ??= $rename->getTarget()->getPathname();
        }

        return $referenceTargetsByExt;
    }

    /**
     * Resolves the explanatory target used in "Duplicate of ..." info lines.
     *
     * The method prefers a non-duplicate target with the same extension and only
     * falls back to the canonical target when no extension-specific reference exists.
     *
     * @param string                $duplicateTargetPath   Duplicate target path being explained
     * @param string                $canonicalTargetPath   Canonical group target path
     * @param array<string, string> $referenceTargetsByExt First non-duplicate target path per extension
     * @param string|null           $sourceBaseDirectory   Base directory for relative display
     *
     * @return string Relative explanatory target path for CLI output
     */
    private function resolveDuplicateReferenceTargetPath(
        string $duplicateTargetPath,
        string $canonicalTargetPath,
        array $referenceTargetsByExt,
        ?string $sourceBaseDirectory,
    ): string {
        $normalizedExtension = FileHelper::normalizeExtension(
            pathinfo($duplicateTargetPath, PATHINFO_EXTENSION),
        );
        $duplicateReferenceTargetPath = $referenceTargetsByExt[$normalizedExtension] ?? $canonicalTargetPath;

        return PathHelper::relativizePath($duplicateReferenceTargetPath, $sourceBaseDirectory);
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
        return $this->diffHighlighter->highlightDiff($source, $target, $baseColor);
    }
}
