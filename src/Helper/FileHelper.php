<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use DateMalformedStringException;
use DateTimeImmutable;
use MagicSunday\Renamer\Constants;
use SplFileInfo;

use function abs;
use function basename;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function strtolower;

/**
 * Shared file-related utility methods used across the rename pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class FileHelper
{
    /**
     * Returns the filename without extension. Handles the edge case where
     * a file has no extension (avoids stripping a trailing dot).
     *
     * @param SplFileInfo $file File to extract the basename from
     */
    public static function basenameWithoutExtension(SplFileInfo $file): string
    {
        $extension = $file->getExtension();

        if ($extension === '') {
            return $file->getBasename();
        }

        return $file->getBasename('.' . $extension);
    }

    /**
     * Normalizes a file extension to lowercase and maps common aliases.
     * Currently maps: jpeg → jpg. Empty extensions are returned as-is.
     *
     * @param string $extension Raw file extension (without leading dot)
     *
     * @return string Normalized lowercase extension
     */
    public static function normalizeExtension(string $extension): string
    {
        if ($extension === '') {
            return '';
        }

        $normalized = strtolower($extension);

        return match ($normalized) {
            'jpeg'  => 'jpg',
            default => $normalized,
        };
    }

    /**
     * Strips an existing "-duplicate-NNN" suffix from a basename.
     * Uses proper regex escaping and end-of-string anchoring.
     *
     * @param string $basename Basename without extension
     */
    public static function stripDuplicateSuffix(string $basename): string
    {
        return preg_replace(
            '/' . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '\d+$/',
            '',
            $basename
        ) ?? $basename;
    }

    /**
     * Computes the date drift in days between dates found in source and target filenames.
     * Returns null when either filename does not contain a recognizable date pattern.
     *
     * @param string $sourcePath Source file path
     * @param string $targetPath Target file path
     *
     * @return int|null Absolute drift in days, or null when dates cannot be extracted
     */
    public static function computeDateDrift(string $sourcePath, string $targetPath): ?int
    {
        $sourceDate = self::extractDateFromPath($sourcePath);
        $targetDate = self::extractDateFromPath($targetPath);

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
     * Supports:
     * - YYYY-MM-DD, YYYY_MM_DD, YYYY.MM.DD (with any separator)
     * - YYYYMMDD (compact, also embedded like IMG_20240330_121624.jpg)
     *
     * @param string $path File path whose basename is checked for a date pattern
     *
     * @return DateTimeImmutable|null Extracted date, or null when no pattern matches
     */
    public static function extractDateFromPath(string $path): ?DateTimeImmutable
    {
        $basename = basename($path);

        // Pattern 1: YYYY-MM-DD or YYYY_MM_DD or YYYY.MM.DD
        if (preg_match('/(\d{4})[-_.](\d{2})[-_.](\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Pattern 2: YYYYMMDD (8 digits starting with 19xx or 20xx)
        if (preg_match('/((?:19|20)\d{2})(\d{2})(\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
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
    private static function tryCreateDate(int $year, int $month, int $day): ?DateTimeImmutable
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
