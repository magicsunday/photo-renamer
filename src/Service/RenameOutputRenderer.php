<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use DateMalformedStringException;
use DateTimeImmutable;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use Symfony\Component\Console\Style\SymfonyStyle;

use function abs;
use function basename;
use function count;
use function is_string;
use function max;
use function mb_strlen;
use function preg_match;
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
class RenameOutputRenderer
{
    /**
     * @param SymfonyStyle $io Console IO for status output
     */
    public function __construct(private readonly SymfonyStyle $io)
    {
    }

    /**
     * Builds a merged, path-sorted list of all output entries from rename operations
     * and skipped files for display during the rename phase.
     *
     * @return array{list<array<string, mixed>>, int, int, int} Tuple of [entries, maxFilenameLength, skippedCount, errorCount]
     */
    public function buildOutputEntries(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options,
        RenameResult $result,
        ?string $sourceBaseDirectory,
        ?string $targetBaseDirectory,
    ): array {
        $maxFilenameLength = 0;

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $relativeSource = FileSystemService::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);

                if (mb_strlen($relativeSource) > $maxFilenameLength) {
                    $maxFilenameLength = mb_strlen($relativeSource);
                }
            }
        }

        foreach ($result->skippedFiles as $skippedFile) {
            $relativeSource = FileSystemService::relativizePath($skippedFile->getFile()->getPathname(), $sourceBaseDirectory);

            if (mb_strlen($relativeSource) > $maxFilenameLength) {
                $maxFilenameLength = mb_strlen($relativeSource);
            }
        }

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
                $isDuplicateTarget = $renameBasename !== $canonicalBasename
                    && str_contains($renameBasename, Constants::DUPLICATE_IDENTIFIER);
                $isNoOp           = $rename->getSource()->getPathname() === $rename->getTarget()->getPathname();
                $isCanonicalEntry = $isNoOp
                    || ($options->listAll && $rename->getSource()->getPathname() === $canonicalTargetPath);

                $sourcePath = FileSystemService::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);
                $targetPath = FileSystemService::relativizePath($rename->getTarget()->getPathname(), $targetBaseDirectory);

                $sourcePathname = $rename->getSource()->getPathname();

                if ($isDuplicateTarget) {
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
                    $driftDays = $this->computeDateDrift($sourcePath, $targetPath);

                    if ($driftDays !== null && $driftDays > $options->maxDateDrift) {
                        $tag = OutputEntryTag::Warning;
                    }
                }

                $isWarning       = $tag === OutputEntryTag::Warning;
                $isFallbackEntry = $tag === OutputEntryTag::Fallback;
                $shouldSkip      = ($options->skipDuplicates && $isDuplicateTarget)
                    || ($options->skipFallback && $isFallbackEntry)
                    || $isWarning;
                $shouldPerformOperation = ($shouldSkip === false) && ($isCanonicalEntry === false);

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
                'sourcePath' => FileSystemService::relativizePath($skippedFile->getFile()->getPathname(), $sourceBaseDirectory),
                'reason'     => ucfirst($skippedFile->getReason()),
                'tag'        => $skippedFile->isError() ? OutputEntryTag::Error : OutputEntryTag::Skipped,
            ];
        }

        usort($outputEntries, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);

        return [$outputEntries, $maxFilenameLength, $skippedCount, $errorCount];
    }

    /**
     * Renders the summary table with file counts and statistics.
     *
     * @param array<string, int> $counters
     */
    public function renderSummary(array $counters, bool $dryRun): void
    {
        $this->io->newLine();
        $this->io->text('<fg=cyan>Summary</>');
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

        if ($counters['plannedCopies'] > 0) {
            $rows[] = ['Planned copies', (string) $counters['plannedCopies']];
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

        $maxLabelLength = 0;
        $maxValueLength = 0;

        foreach ($rows as $row) {
            $maxLabelLength = max($maxLabelLength, strlen($row[0]));
            $maxValueLength = max($maxValueLength, strlen($row[1]));
        }

        foreach ($rows as $row) {
            $this->io->text(sprintf(
                ' %-' . $maxLabelLength . 's  %' . $maxValueLength . 's',
                $row[0],
                $row[1],
            ));
        }

        $this->io->newLine();
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
     * Computes the date drift in days between dates found in source and target filenames.
     * Returns null when either filename does not contain a recognizable date pattern.
     *
     * Supports:
     * - YYYY-MM-DD, YYYY_MM_DD, YYYY.MM.DD (with any separator)
     * - YYYYMMDD (compact, also embedded like IMG_20240330_121624.jpg)
     */
    private function computeDateDrift(string $sourcePath, string $targetPath): ?int
    {
        $sourceDate = $this->extractDateFromPath($sourcePath);
        $targetDate = $this->extractDateFromPath($targetPath);

        if (!$sourceDate instanceof DateTimeImmutable || !$targetDate instanceof DateTimeImmutable) {
            return null;
        }

        $days = $sourceDate->diff($targetDate)->days;

        if ($days === false) {
            return null;
        }

        return abs($days);
    }

    /**
     * Extracts a date from a filename matching common patterns (YYYY-MM-DD with
     * separators or YYYYMMDD compact). Returns null when no recognizable date is found.
     *
     * @param string $path File path whose basename is checked for a date pattern
     *
     * @return DateTimeImmutable|null Extracted date, or null when no pattern matches
     */
    private function extractDateFromPath(string $path): ?DateTimeImmutable
    {
        $basename = basename($path);

        // Pattern 1: YYYY-MM-DD or YYYY_MM_DD or YYYY.MM.DD
        if (preg_match('/(\d{4})[-_.](\d{2})[-_.](\d{2})/', $basename, $matches) === 1) {
            return $this->tryCreateDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Pattern 2: YYYYMMDD (8 digits starting with 19xx or 20xx)
        if (preg_match('/((?:19|20)\d{2})(\d{2})(\d{2})/', $basename, $matches) === 1) {
            return $this->tryCreateDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        return null;
    }

    /**
     * Creates a validated DateTimeImmutable from year/month/day components.
     * Returns null when the components form an invalid date (e.g. Feb 30).
     *
     * @param int $year  Four-digit year
     * @param int $month Month (1-12)
     * @param int $day   Day (1-31)
     *
     * @return DateTimeImmutable|null Validated date, or null on invalid input
     */
    private function tryCreateDate(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        try {
            $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

            // Validate the date is real (not Feb 30 etc.)
            if ((int) $date->format('Y') !== $year || (int) $date->format('m') !== $month || (int) $date->format('d') !== $day) {
                return null;
            }

            return $date;
        } catch (DateMalformedStringException) {
            return null;
        }
    }
}
