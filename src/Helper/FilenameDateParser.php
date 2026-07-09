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

use function basename;
use function preg_match;
use function sprintf;

/**
 * Parses date and date-time information from filenames using the naming
 * patterns that the tool already understands across rename, verify, and
 * metadata-reliability flows.
 *
 * The parser stays intentionally narrow: it only recognizes the supported
 * filename conventions and returns immutable date objects. It does not decide
 * whether a parsed timestamp should win over metadata, nor whether drift is
 * acceptable for a command-specific workflow.
 */
final class FilenameDateParser
{
    /**
     * Extracts a date from a filename matching common patterns.
     *
     * The returned date is normalized to midnight because callers that only
     * care about calendar-day semantics should not have to strip time
     * components themselves.
     *
     * @param string $path File path whose basename is checked for a date pattern.
     *
     * @return DateTimeImmutable|null Extracted date, or null when no supported pattern matches.
     */
    public static function extractDateFromPath(string $path): ?DateTimeImmutable
    {
        return self::extractDateTimeFromPath($path)?->setTime(0, 0);
    }

    /**
     * Extracts a date and optional time from a filename.
     *
     * Supported patterns intentionally mirror the historical rename rules:
     * - `YYYY-MM-DD_HH-MM-SS(-mmm)`
     * - `YYYYMMDD_HHMMSS`
     * - `YYYY-MM-DD`
     * - `YYYYMMDD`
     *
     * @param string $path File path whose basename is checked for a date+time pattern.
     *
     * @return DateTimeImmutable|null Extracted date with time precision, or null when no supported pattern matches.
     */
    public static function extractDateTimeFromPath(string $path): ?DateTimeImmutable
    {
        $basename = basename($path);

        if (preg_match('/(\d{4})[-_.](\d{2})[-_.](\d{2})[-_](\d{2})[-_.](\d{2})[-_.](\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDateTime(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                (int) $matches[4],
                (int) $matches[5],
                (int) $matches[6],
            );
        }

        if (preg_match('/((?:19|20)\d{2})(\d{2})(\d{2})[-_](\d{2})(\d{2})(\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDateTime(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                (int) $matches[4],
                (int) $matches[5],
                (int) $matches[6],
            );
        }

        if (preg_match('/(\d{4})[-_.](\d{2})[-_.](\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDateTime((int) $matches[1], (int) $matches[2], (int) $matches[3], 0, 0, 0);
        }

        if (preg_match('/((?:19|20)\d{2})(\d{2})(\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDateTime((int) $matches[1], (int) $matches[2], (int) $matches[3], 0, 0, 0);
        }

        return null;
    }

    /**
     * Creates a validated immutable date from raw numeric components.
     *
     * The additional calendar validation step prevents PHP from silently rolling
     * invalid values such as February 30 into the next month.
     *
     * @param int $year   Four-digit year.
     * @param int $month  Month number.
     * @param int $day    Day number.
     * @param int $hour   Hour number.
     * @param int $minute Minute number.
     * @param int $second Second number.
     *
     * @return DateTimeImmutable|null Validated timestamp, or null for invalid input.
     */
    private static function tryCreateDateTime(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
    ): ?DateTimeImmutable {
        if (($month < 1) || ($month > 12) || ($day < 1) || ($day > 31)) {
            return null;
        }

        if (($hour < 0) || ($hour > 23) || ($minute < 0) || ($minute > 59) || ($second < 0) || ($second > 59)) {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable(
                sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            );

            if (
                ((int) $dateTime->format('Y') !== $year)
                || ((int) $dateTime->format('m') !== $month)
                || ((int) $dateTime->format('d') !== $day)
            ) {
                return null;
            }

            return $dateTime;
        } catch (DateMalformedStringException) {
            return null;
        }
    }
}
