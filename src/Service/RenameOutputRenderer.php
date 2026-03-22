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
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_fill;
use function array_slice;
use function count;
use function implode;
use function is_string;
use function max;
use function mb_str_split;
use function min;
use function sprintf;
use function str_contains;
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
            $canonicalBasename   = $fileDuplicate->getTarget()->getBasename(
                '.' . $fileDuplicate->getTarget()->getExtension()
            );

            foreach ($fileDuplicate->getRenames() as $rename) {
                $renameBasename = $rename->getTarget()->getBasename(
                    '.' . $rename->getTarget()->getExtension()
                );
                // A file is a duplicate if its target basename differs from the canonical
                // and contains the duplicate identifier. This is status-based: both no-op
                // duplicates (already correctly named) and rename targets are marked as [D].
                $isDuplicateTarget = ($renameBasename !== $canonicalBasename)
                    && str_contains($renameBasename, Constants::DUPLICATE_IDENTIFIER);
                $isNoOp           = $rename->getSource()->getPathname() === $rename->getTarget()->getPathname();
                $isCanonicalEntry = ($renameBasename === $canonicalBasename)
                    && ($isNoOp || ($options->listAll && $rename->getSource()->getPathname() === $canonicalTargetPath));

                $sourcePath = FileHelper::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);
                $targetPath = FileHelper::relativizePath($rename->getTarget()->getPathname(), $sourceBaseDirectory);

                $sourcePathname = $rename->getSource()->getPathname();

                if (isset($result->livePhotoConflictFiles[$sourcePathname])) {
                    $tag = OutputEntryTag::Candidate;
                } elseif ($isDuplicateTarget) {
                    $tag = OutputEntryTag::Duplicate;
                } elseif ($isCanonicalEntry) {
                    $tag = OutputEntryTag::Original;
                } elseif (isset($result->ambiguousTimezoneFiles[$sourcePathname])) {
                    $tag = OutputEntryTag::Warning;
                } elseif (isset($result->fallbackDateFiles[$sourcePathname])) {
                    $tag = OutputEntryTag::Fallback;
                } else {
                    $tag = OutputEntryTag::Rename;
                }

                if (
                    ($tag === OutputEntryTag::Rename || $tag === OutputEntryTag::Fallback)
                    && ($options->maxDateDrift !== null)
                    && ($options->maxDateDrift > 0)
                ) {
                    $driftDays = FileHelper::computeDateDrift($sourcePath, $targetPath);

                    if ($driftDays !== null && $driftDays > $options->maxDateDrift) {
                        $tag = OutputEntryTag::Warning;
                    }
                }

                $isCandidate     = $tag === OutputEntryTag::Candidate;
                $isWarning       = $tag === OutputEntryTag::Warning;
                $isFallbackEntry = $tag === OutputEntryTag::Fallback;
                $shouldSkip      = ($options->skipDuplicates && $isDuplicateTarget)
                    || $isCandidate
                    || ($options->skipFallback && $isFallbackEntry)
                    || $isWarning;
                $shouldPerformOperation = ($shouldSkip === false) && !$isNoOp;

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
                ];
            }
        }

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

        usort($outputEntries, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);

        return [$outputEntries, $skippedCount, $errorCount];
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
