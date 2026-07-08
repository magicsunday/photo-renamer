<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Computes whole-day drift between filename-derived timestamps and metadata
 * timestamps.
 *
 * This helper intentionally stays mechanical. It centralizes the existing
 * filename-date drift math so callers can share the same day-difference
 * semantics without embedding duplicate extraction logic in unrelated classes.
 */
final class DateDriftCalculator
{
    /**
     * Calculates the absolute day drift between two date values extracted from paths.
     *
     * @param string $sourcePath Source path whose basename may contain a date.
     * @param string $targetPath Target path whose basename may contain a date.
     *
     * @return int|null Absolute drift in days, or null when either path has no parseable date.
     */
    public static function computeDateDrift(string $sourcePath, string $targetPath): ?int
    {
        $sourceDate = FilenameDateParser::extractDateFromPath($sourcePath);
        $targetDate = FilenameDateParser::extractDateFromPath($targetPath);

        if (!($sourceDate instanceof DateTimeImmutable) || !($targetDate instanceof DateTimeImmutable)) {
            return null;
        }

        return self::calculateDateDriftInDays($sourceDate, $targetDate);
    }

    /**
     * Calculates the absolute day drift between a filename date and a metadata date.
     *
     * The metadata side is normalized to midnight so this matches the existing
     * calendar-day semantics already used in verification and warning output.
     *
     * @param string            $path         File path whose basename may contain a date.
     * @param DateTimeInterface $metadataDate Metadata date to compare against.
     *
     * @return int|null Absolute drift in days, or null when the path has no parseable date.
     */
    public static function computeDateDriftFromDateTime(string $path, DateTimeInterface $metadataDate): ?int
    {
        $fileDate = FilenameDateParser::extractDateFromPath($path);

        if (!$fileDate instanceof DateTimeImmutable) {
            return null;
        }

        $metadataDateOnly = DateTimeImmutable::createFromInterface($metadataDate)
            ->setTime(0, 0);

        return self::calculateDateDriftInDays($fileDate, $metadataDateOnly);
    }

    /**
     * Calculates the absolute whole-day drift between two dates.
     *
     * @param DateTimeInterface $expectedDateTime Reference date.
     * @param DateTimeInterface $actualDateTime   Compared date.
     *
     * @return int Absolute drift in days.
     */
    public static function calculateDateDriftInDays(
        DateTimeInterface $expectedDateTime,
        DateTimeInterface $actualDateTime,
    ): int {
        return $expectedDateTime->diff($actualDateTime)->days;
    }
}
