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
use DateTimeInterface;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\LinkConfig;
use SplFileInfo;

use function array_map;
use function basename;
use function explode;
use function getenv;
use function implode;
use function in_array;
use function is_dir;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function realpath;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const DIRECTORY_SEPARATOR;

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
     * Reads an environment variable and returns null for missing or empty values.
     *
     * @param string $name Environment variable name
     *
     * @return string|null Value, or null when absent/empty
     */
    public static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && ($value !== '') ? $value : null;
    }

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
     * Currently maps: jpeg -> jpg. Empty extensions are returned as-is.
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
     * Converts an absolute pathname to a display-friendly relative path by stripping
     * the base directory prefix and prepending the base directory's own name. Falls back
     * to the full pathname when the path does not start with the base or when the base
     * directory is a relative path.
     *
     * @param string      $pathname      Absolute file path
     * @param string|null $baseDirectory Normalized base directory (trailing separator stripped)
     *
     * @return string Relative or absolute path suitable for display
     */
    public static function relativizePath(string $pathname, ?string $baseDirectory): string
    {
        if (($baseDirectory === null) || ($baseDirectory === '')) {
            return $pathname;
        }

        $normalizedBase = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        if ($normalizedBase === '') {
            return $pathname;
        }

        if (!str_starts_with($normalizedBase, DIRECTORY_SEPARATOR)) {
            return $pathname;
        }

        $prefix = $normalizedBase . DIRECTORY_SEPARATOR;

        if (str_starts_with($pathname, $prefix)) {
            return substr($pathname, strlen($prefix));
        }

        return $pathname;
    }

    /**
     * Resolves and validates a directory path from a CLI input argument.
     * Returns the canonicalized absolute path or null if the path is invalid.
     *
     * @param string|null $directory Raw directory path from CLI input
     *
     * @return string|null Absolute directory path, or null if invalid
     */
    public static function resolveDirectory(?string $directory): ?string
    {
        if (!is_string($directory)) {
            return null;
        }

        $resolved = realpath($directory);

        if (($resolved === false) || !is_dir($resolved)) {
            return null;
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
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

        if (!($sourceDate instanceof DateTimeImmutable) || !($targetDate instanceof DateTimeImmutable)) {
            return null;
        }

        $days = $sourceDate->diff($targetDate)->days;

        return $days !== false ? $days : 0;
    }

    /**
     * Computes the date drift in days between a filename-extracted date and a given
     * metadata date. Returns null when the filename does not contain a recognizable
     * date pattern.
     *
     * @param string            $path         File path whose basename is checked for a date pattern
     * @param DateTimeInterface $metadataDate Metadata date to compare against
     *
     * @return int|null Absolute drift in days, or null when a date cannot be extracted from the path
     */
    public static function computeDateDriftFromDateTime(string $path, DateTimeInterface $metadataDate): ?int
    {
        $fileDate = self::extractDateFromPath($path);

        if (!$fileDate instanceof DateTimeImmutable) {
            return null;
        }

        $metadataDateOnly = DateTimeImmutable::createFromInterface($metadataDate)
            ->setTime(0, 0);

        $days = $fileDate->diff($metadataDateOnly)->days;

        return $days !== false ? $days : 0;
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
        return self::extractDateTimeFromPath($path)?->setTime(0, 0);
    }

    /**
     * Extracts a date and optional time from a filename. Returns a DateTimeImmutable
     * with time precision when the filename contains a time component, or with
     * 00:00:00 when only a date is present. Returns null when no date is found.
     *
     * Supports:
     * - 2013-10-17_10-36-18-000.mp4 -> 2013-10-17 10:36:18
     * - 2013-10-17_10-36-18.mp4     -> 2013-10-17 10:36:18
     * - 2013-10-17.jpg              -> 2013-10-17 00:00:00
     * - 20131017_103618.jpg         -> 2013-10-17 10:36:18
     * - IMG_20131017_103618.jpg     -> 2013-10-17 10:36:18
     *
     * @param string $path File path whose basename is checked for a date+time pattern
     *
     * @return DateTimeImmutable|null Extracted date with time, or null when no pattern matches
     */
    public static function extractDateTimeFromPath(string $path): ?DateTimeImmutable
    {
        $basename = basename($path);

        // Pattern 1: YYYY-MM-DD_HH-MM-SS(-mmm) with separators
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

        // Pattern 2: YYYYMMDD_HHMMSS compact (also embedded like IMG_20131017_103618.jpg)
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

        // Pattern 3: YYYY-MM-DD or YYYY_MM_DD or YYYY.MM.DD (date only)
        if (preg_match('/(\d{4})[-_.](\d{2})[-_.](\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDateTime((int) $matches[1], (int) $matches[2], (int) $matches[3], 0, 0, 0);
        }

        // Pattern 4: YYYYMMDD compact (date only)
        if (preg_match('/((?:19|20)\d{2})(\d{2})(\d{2})/', $basename, $matches) === 1) {
            return self::tryCreateDateTime((int) $matches[1], (int) $matches[2], (int) $matches[3], 0, 0, 0);
        }

        return null;
    }

    /**
     * Creates a validated DateTimeImmutable from year/month/day/hour/minute/second components.
     * Returns null when the components form an invalid date or time.
     *
     * @param int $year   Four-digit year
     * @param int $month  Month (1-12)
     * @param int $day    Day (1-31)
     * @param int $hour   Hour (0-23)
     * @param int $minute Minute (0-59)
     * @param int $second Second (0-59)
     *
     * @return DateTimeImmutable|null Validated date+time, or null on invalid input
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

            // Validate the date is real (not Feb 30 etc.)
            if (
                (int) $dateTime->format('Y') !== $year
                || (int) $dateTime->format('m') !== $month
                || (int) $dateTime->format('d') !== $day
            ) {
                return null;
            }

            return $dateTime;
        } catch (DateMalformedStringException) {
            return null;
        }
    }

    /**
     * Converts a Unix file path to a file:// URL suitable for terminal hyperlinks.
     *
     * @param string $nativePath Absolute Unix file path
     *
     * @return string file:// URL
     */
    public static function pathToFileUrl(string $nativePath): string
    {
        // Normalize backslashes for Windows paths passed via FILE_LINK_BASE
        $path = str_replace('\\', '/', $nativePath);

        // Encode each path segment (preserving / separators)
        $parts   = explode('/', $path);
        $encoded = implode('/', array_map(rawurlencode(...), $parts));

        // Drive letter: F:/... → file:///F:/...
        if (preg_match('/^[A-Za-z]%3A/', $encoded) === 1) {
            $encoded = $encoded[0] . ':' . substr($encoded, 4);

            return 'file:///' . $encoded;
        }

        // Unix absolute: /srv/photos → file:///srv/photos
        return 'file://' . $encoded;
    }

    /**
     * Wraps a display path in a Symfony Console terminal hyperlink tag.
     * Returns the plain display text when no link config is set or disabled.
     *
     * Computes the host-accessible path by replacing FILE_LINK_ROOT with
     * FILE_LINK_BASE and appending the source directory offset + relative path.
     *
     * @param string      $displayPath     Text to show in the terminal
     * @param string      $relativePath    Relative path to the file (relative to sourceDirectory)
     * @param string|null $sourceDirectory Absolute source directory passed to the command
     * @param LinkConfig  $linkConfig      Link configuration from env vars
     * @param string|null $color           Symfony Console color name (e.g. 'yellow') to apply to the display text
     *
     * @return string Display text with optional color and/or href formatting
     */
    public static function linkifyPath(
        string $displayPath,
        string $relativePath,
        ?string $sourceDirectory,
        LinkConfig $linkConfig,
        ?string $color = null,
    ): string {
        if (!$linkConfig->isEnabled()) {
            return $color !== null
                ? sprintf('<fg=%s>%s</>', $color, $displayPath)
                : $displayPath;
        }

        // Normalize backslashes in link config values — these may contain
        // Windows paths (e.g. FILE_LINK_BASE=Z:\Photos) provided by the user
        // even though the application itself runs on Linux.
        $normalizedRoot   = rtrim(str_replace('\\', '/', $linkConfig->root ?? ''), '/');
        $normalizedSource = rtrim($sourceDirectory ?? '', '/');
        $offset           = '';

        if (($normalizedSource !== '') && str_starts_with($normalizedSource, $normalizedRoot)) {
            $offset = substr($normalizedSource, strlen($normalizedRoot));
            $offset = ltrim($offset, '/');
        }

        $normalizedBase = rtrim(str_replace('\\', '/', $linkConfig->base ?? ''), '/');
        $fullFilePath   = $normalizedBase . '/' . ($offset !== '' ? $offset . '/' : '') . $relativePath;

        if (!in_array($linkConfig->protocol, [null, '', 'file'], true)) {
            // Custom protocol (e.g. photo-select://) links to the file directly
            $url = self::pathToFileUrl($fullFilePath);
            $url = preg_replace('/^file/', $linkConfig->protocol ?? '', $url) ?? $url;
        } else {
            // Default file:// links to the parent directory to open a file manager
            $dirPath = implode('/', explode('/', $fullFilePath, -1));
            $url     = self::pathToFileUrl($dirPath . '/');
        }

        if ($color !== null) {
            return sprintf('<fg=%s;href=%s>%s</>', $color, $url, $displayPath);
        }

        return sprintf('<href=%s>%s</>', $url, $displayPath);
    }
}
