<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use MagicSunday\Renamer\Constants;
use SplFileInfo;

use function getenv;
use function is_string;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strrpos;
use function strtolower;
use function substr;

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
     * Reads an environment variable and returns its value as a string.
     *
     * @param string $name The name of the environment variable to retrieve.
     *
     * @return string|null The value of the environment variable, or null if it
     *                     is not set, is empty, or consists only of whitespace.
     */
    public static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && ($value !== '') ? $value : null;
    }

    /**
     * Returns the filename without its extension.
     *
     * This method correctly handles edge cases where a file has no extension,
     * ensuring that no trailing dot is accidentally stripped or added.
     *
     * @param SplFileInfo $file The file from which to extract the basename.
     *
     * @return string The filename without extension.
     */
    public static function basenameWithoutExtension(SplFileInfo $file): string
    {
        $pathname          = str_replace('\\', '/', $file->getPathname());
        $separatorPosition = strrpos($pathname, '/');
        $portableBasename  = $separatorPosition === false ? $pathname : substr($pathname, $separatorPosition + 1);
        $extensionPosition = strrpos($portableBasename, '.');

        if (($extensionPosition === false) || ($extensionPosition === 0)) {
            return $portableBasename;
        }

        return substr($portableBasename, 0, $extensionPosition);
    }

    /**
     * Normalizes a file extension to lowercase and maps common aliases.
     *
     * Currently, this method primarily maps 'jpeg' to 'jpg' for consistency
     * across the application. Extensions are always returned in lowercase.
     *
     * @param string $extension The raw file extension (without the leading dot).
     *
     * @return string The normalized lowercase extension.
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
     * Strips an existing duplicate identifier suffix from a basename.
     *
     * This method removes suffixes like "-duplicate-001" from the end of a
     * filename, which is necessary when re-processing files that have already
     * been renamed as duplicates in a previous run.
     *
     * @param string $basename The basename without extension to be cleaned.
     *
     * @return string The cleaned basename without the duplicate suffix.
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
     * Formats a byte count as a human-readable string (e.g. "1.5 MB").
     * Supports B, KB, MB, and GB units.
     *
     * @param int $bytes Number of bytes to format
     *
     * @return string Human-readable size string
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $kb = $bytes / 1024;

        if ($kb < 1024) {
            return sprintf('%.1f KB', $kb);
        }

        $mb = $kb / 1024;

        if ($mb < 1024) {
            return sprintf('%.1f MB', $mb);
        }

        $gb = $mb / 1024;

        return sprintf('%.1f GB', $gb);
    }
}
