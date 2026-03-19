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

use function preg_quote;
use function preg_replace;
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
}
