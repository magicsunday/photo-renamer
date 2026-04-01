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
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_fill;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function is_string;
use function max;
use function mb_str_split;
use function mb_strlen;
use function min;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function ucfirst;
use function usort;

/**
 * Handles all console output rendering for the rename phase: building the
 * merged output entry list, rendering the summary statistics table, and
 * providing display-related query helpers. Extracted from {@see FileSystemService}
 * to separate rendering concerns from file I/O operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameOutputRenderer
{
    /**
     * @param SymfonyStyle $io Console IO for status output
     */
    public function __construct(private SymfonyStyle $io)
    {
    }

    /**
     * Builds a merged, path-sorted list of all output entries from rename operations
     * and skipped files for display during the rename phase.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection of file duplicates
     * @param RenameOptions           $options                 Options controlling the rename operation
     * @param RenameResult            $result                  Pipeline-computed results (scanned files, collisions, skips)
     * @param string|null             $sourceBaseDirectory     Normalized base directory for path relativization
     *
     * @return array{list<array<string, mixed>>, int, int} Tuple of [entries, skippedCount, errorCount]
     */
    public function buildOutputEntries(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options,
        RenameResult $result,
        ?string $sourceBaseDirectory,
    ): array {
        /** @var list<array<string, mixed>> $outputEntries */
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

                $outputEntries[] = [
                    'sortKey'                => $rename->getSource()->getPathname(),
                    'type'                   => 'rename',
                    'sourcePath'             => $sourcePath,
                    'targetPath'             => $targetPath,
                    'tag'                    => $tag,
                    'isDuplicateTarget'      => $isDuplicateTarget,
                    'shouldSkip'             => $shouldSkip,
                    'shouldPerformOperation' => $shouldPerformOperation,
                    'rename'                 => $rename,
                    'warningReason'          => $warningReason,
                ];
            }
        }

        [$skippedCount, $errorCount] = $this->appendSkippedFileEntries($outputEntries, $result, $sourceBaseDirectory);

        usort($outputEntries, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);

        return [$outputEntries, $skippedCount, $errorCount];
    }

    /**
     * Resolves the output entry tag for a file based on its metadata quality,
     * duplicate status, and canonical position.
     *
     * Priority chain (top to bottom): [C] > [W] > [F] > [D] > [O] > [R].
     * Exception: [O] wins for no-ops (!$isNoOp guard on [W], [F], [D]).
     *
     * @param string       $sourcePathname    Absolute path of the source file
     * @param bool         $isDuplicateTarget Whether the file is a duplicate (has -duplicate- suffix)
     * @param bool         $isNoOp            Whether source and target paths are identical
     * @param bool         $isCanonicalEntry  Whether the file is the canonical entry in its group
     * @param RenameResult $result            Result carrying quality flags
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
     * @param list<array{string, string}> $rows Label/value pairs to display
     * @param SymfonyStyle|null           $io   Console IO to render to (defaults to constructor-injected IO)
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
     * Renders a summary section with header, aligned table and trailing newline.
     * Shared by all commands for consistent summary output.
     *
     * @param list<array{string, string}> $rows Label/value pairs to display
     * @param SymfonyStyle|null           $io   Console IO to render to (defaults to constructor-injected IO)
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
     * @return array{list<array<string, mixed>>, int, int} Tuple of [entries, skippedCount, errorCount]
     */
    public function buildOutputEntriesFromPlan(
        ExecutionPlan $plan,
        RenameOptions $options,
        RenameResult $result,
        ?string $sourceBaseDirectory = null,
    ): array {
        /** @var list<array<string, mixed>> $outputEntries */
        $outputEntries = [];

        foreach ($plan->groups as $group) {
            foreach ($group->items as $item) {
                $sourcePath = FileHelper::relativizePath($item->sourcePath, $sourceBaseDirectory);
                $targetPath = FileHelper::relativizePath($item->targetPath, $sourceBaseDirectory);

                $tag = $this->resolveItemTag($item);

                [$tag, $warningReason] = $this->applyDateDriftCheck($tag, $item->warningReason ?? $item->executionBlockReason, $sourcePath, $targetPath, $options);

                // Use isExecutable from the ExecutionItem to determine skip status.
                // Date drift (applied above) can also trigger a skip via the tag.
                $shouldSkip = (!$item->isExecutable && !$item->isNoOp)
                    || ($tag === OutputEntryTag::Warning)
                    || ($tag === OutputEntryTag::Candidate);
                $shouldPerformOperation = !$shouldSkip && !$item->isNoOp;

                $outputEntries[] = [
                    'sortKey'                => $item->sourcePath,
                    'type'                   => 'rename',
                    'sourcePath'             => $sourcePath,
                    'targetPath'             => $targetPath,
                    'tag'                    => $tag,
                    'isDuplicateTarget'      => $item->isDuplicateTarget,
                    'shouldSkip'             => $shouldSkip,
                    'shouldPerformOperation' => $shouldPerformOperation,
                    'warningReason'          => $warningReason,
                ];
            }
        }

        [$skippedCount, $errorCount] = $this->appendSkippedFileEntries($outputEntries, $result, $sourceBaseDirectory);

        usort($outputEntries, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);

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
     * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int}
     */
    public function renderPlanEntries(
        ExecutionPlan $plan,
        RenameOptions $options,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
        ?RenameResult $result = null,
    ): array {
        [$outputEntries] = $this->buildOutputEntriesFromPlan(
            $plan,
            $options,
            $result ?? new RenameResult(),
            $sourceBaseDirectory,
        );

        return $this->renderEntryLines($outputEntries, $sourceBaseDirectory, $showFilter);
    }

    /**
     * Renders the summary section combining ExecutionPlan counters with
     * RenameResult analysis data. Delegates to the shared
     * {@see renderSummary()} method.
     *
     * @param ExecutionPlan                                                                    $plan              The execution plan
     * @param RenameResult                                                                     $result            Scan/analysis summary data
     * @param array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int} $executionCounters
     *                                                                                                            Counters from renderPlanEntries()
     * @param bool                                                                             $dryRun            Whether the run is dry-run mode
     */
    public function renderPlanSummary(
        ExecutionPlan $plan,
        RenameResult $result,
        array $executionCounters,
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
            'scannedFiles'     => $result->scannedFiles,
            'skippedCount'     => $skippedCount,
            'errorCount'       => $errorCount,
            'livePhotoGroups'  => $plan->livePhotoGroupCount(),
            'namingCollisions' => $result->namingCollisions,
            'fileCount'        => $executionCounters['fileCount'],
            'duplicateCount'   => $executionCounters['duplicateCount'],
            'plannedMoves'     => $executionCounters['plannedMoves'],
            'plannedSkips'     => $executionCounters['plannedSkips'],
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
     * @param list<array<string, mixed>> $outputEntries       Output entries array (modified by reference)
     * @param RenameResult               $result              Result carrying skipped files
     * @param string|null                $sourceBaseDirectory Base directory for path relativization
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

            $outputEntries[] = [
                'sortKey'    => $skippedFile->getFile()->getPathname(),
                'type'       => 'skip',
                'sourcePath' => FileHelper::relativizePath($skippedFile->getFile()->getPathname(), $sourceBaseDirectory),
                'reason'     => ucfirst($skippedFile->getReason()),
                'tag'        => $skippedFile->isError() ? OutputEntryTag::Error : OutputEntryTag::Skipped,
            ];
        }

        return [$skippedCount, $errorCount];
    }

    /**
     * Renders a list of output entries to the console and returns counters.
     * Used by both the ExecutionPlan and legacy rendering paths.
     *
     * @param list<array<string, mixed>> $outputEntries       Sorted output entries
     * @param string|null                $sourceBaseDirectory Base directory for linkified paths
     * @param list<string>|null          $showFilter          Tag filter (null = show all)
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
                        $this->highlightDiff($sourcePath, $targetPath, 'green'),
                    ));
                }
            }

            if ($isDuplicateTarget) {
                ++$duplicateCount;
            }

            if ($shouldSkip) {
                ++$plannedSkips;
            } elseif ($shouldPerformOperation) {
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
     * Counts the total number of rename operations across all groups in the collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection to inspect
     *
     * @return int Total number of individual rename operations
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
     * Formats a target path with only the changed characters highlighted in bold.
     *
     * First isolates the differing region via common prefix/suffix. Then applies
     * LCS within that region to keep common substrings (e.g. "15-08") in the base
     * color. Short common runs (< 3 chars) within the diff are merged into the
     * highlight to avoid a confusing flickering pattern from single-character matches.
     *
     * @param string $source    Source display path
     * @param string $target    Target display path
     * @param string $baseColor Symfony Console color name for unchanged text
     *
     * @return string Formatted string with diff highlighted
     */
    public function highlightDiff(string $source, string $target, string $baseColor): string
    {
        if ($source === $target) {
            return sprintf('<fg=%s>%s</>', $baseColor, $target);
        }

        $sourceChars = mb_str_split($source);
        $targetChars = mb_str_split($target);
        $sLen        = count($sourceChars);
        $tLen        = count($targetChars);
        $minLen      = min($sLen, $tLen);

        // Common prefix.
        $prefixLen = 0;

        while (($prefixLen < $minLen) && ($sourceChars[$prefixLen] === $targetChars[$prefixLen])) {
            ++$prefixLen;
        }

        // Common suffix.
        $suffixLen = 0;

        while (
            ($suffixLen < ($minLen - $prefixLen))
            && ($sourceChars[$sLen - 1 - $suffixLen] === $targetChars[$tLen - 1 - $suffixLen])
        ) {
            ++$suffixLen;
        }

        $prefix = implode('', array_slice($targetChars, 0, $prefixLen));
        $suffix = $suffixLen > 0 ? implode('', array_slice($targetChars, $tLen - $suffixLen)) : '';

        $sourceMid = array_slice($sourceChars, $prefixLen, $sLen - $prefixLen - $suffixLen);
        $targetMid = array_slice($targetChars, $prefixLen, $tLen - $prefixLen - $suffixLen);

        if ($targetMid === []) {
            return sprintf('<fg=%s>%s</>', $baseColor, $target);
        }

        // LCS within the diff region to find matching runs.
        $inLcs = $this->computeLcsFlags($sourceMid, $targetMid);

        // Merge short common runs (< 3 chars) into highlights to avoid flickering
        // from accidental single-character matches (e.g. a lone "-" or digit).
        $inLcs = $this->mergeShortCommonRuns($inLcs, 3);

        // Build formatted middle.
        $midFormatted = '';
        $buffer       = '';
        $inHighlight  = false;

        foreach ($targetMid as $j => $char) {
            $isChanged = !$inLcs[$j];

            if (($isChanged !== $inHighlight) && ($buffer !== '')) {
                $midFormatted .= $inHighlight
                    ? sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $buffer)
                    : sprintf('<fg=%s>%s</>', $baseColor, $buffer);
                $buffer = '';
            }

            $inHighlight = $isChanged;
            $buffer .= $char;
        }

        $midFormatted .= $inHighlight
            ? sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $buffer)
            : sprintf('<fg=%s>%s</>', $baseColor, $buffer);

        return sprintf('<fg=%s>%s</>', $baseColor, $prefix)
            . $midFormatted
            . sprintf('<fg=%s>%s</>', $baseColor, $suffix);
    }

    /**
     * Merges short common runs (LCS matches) that are shorter than the threshold
     * into the surrounding highlight. This prevents distracting single-character
     * or two-character matches from flickering between colors.
     *
     * @param array<int, bool> $flags     Per-character LCS match flags
     * @param int              $threshold Minimum run length to keep as common
     *
     * @return array<int, bool> Adjusted flags with short runs merged into highlights
     */
    private function mergeShortCommonRuns(array $flags, int $threshold): array
    {
        $count    = count($flags);
        $runStart = 0;

        while ($runStart < $count) {
            // Skip highlighted (false) regions.
            if (!$flags[$runStart]) {
                ++$runStart;

                continue;
            }

            // Found a common (true) run. Measure its length.
            $runEnd = $runStart;

            while (($runEnd < $count) && $flags[$runEnd]) {
                ++$runEnd;
            }

            $runLength = $runEnd - $runStart;

            // Short common run -> merge into highlight. Single-character matches
            // are likely coincidental (e.g. "0" matching between "08" and "10").
            if ($runLength < $threshold) {
                for ($k = $runStart; $k < $runEnd; ++$k) {
                    $flags[$k] = false;
                }
            }

            $runStart = $runEnd;
        }

        return $flags;
    }

    /**
     * Computes which characters in the target array are part of the Longest Common
     * Subsequence (LCS) with the source array. Returns a boolean array where true
     * means the target character at that position matches a source character.
     *
     * @param list<string> $sourceChars Source characters
     * @param list<string> $targetChars Target characters
     *
     * @return array<int, bool> True for each target character that is part of the LCS
     */
    private function computeLcsFlags(array $sourceChars, array $targetChars): array
    {
        $sLen = count($sourceChars);
        $tLen = count($targetChars);

        // Build LCS length table (dimensions: (sLen+1) x (tLen+1), initialized to 0).
        $dp = [];

        for ($i = 0; $i <= $sLen; ++$i) {
            $dp[$i] = array_fill(0, $tLen + 1, 0);
        }

        for ($i = 1; $i <= $sLen; ++$i) {
            for ($j = 1; $j <= $tLen; ++$j) {
                $dp[$i][$j] = ($sourceChars[$i - 1] === $targetChars[$j - 1])
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        // Backtrack to mark which target characters are in the LCS.
        $inLcs = array_fill(0, $tLen, false);
        $i     = $sLen;
        $j     = $tLen;

        while (($i > 0) && ($j > 0)) {
            if ($sourceChars[$i - 1] === $targetChars[$j - 1]) {
                $inLcs[$j - 1] = true;
                --$i;
                --$j;
            } elseif ($dp[$i - 1][$j] > $dp[$i][$j - 1]) {
                --$i;
            } else {
                --$j;
            }
        }

        return $inLcs;
    }
}
