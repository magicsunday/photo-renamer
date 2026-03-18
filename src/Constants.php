<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

use SplFileInfo;

use function preg_quote;
use function preg_replace;

/**
 * Shared constants used across the rename pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class Constants
{
    /**
     * String inserted between the base name and the sequential number
     * when creating duplicate-suffixed filenames (e.g. "photo-duplicate-001.jpg").
     */
    public const string DUPLICATE_IDENTIFIER = '-duplicate-';

    /**
     * Symfony progress bar format string shared across all pipeline phases.
     */
    public const string PROGRESS_BAR_FORMAT = ' %current%/%max% [%bar%] %percent:3s%% | ETA: %estimated:-6s% | Remaining: %remaining:-6s%';

    /**
     * Upper bound for the runtime duplicate suffix fallback loop.
     */
    public const int MAX_DUPLICATE_SUFFIX = 999;

    /**
     * Prefix used to identify Live Photo groups by their duplicate identifier string.
     */
    public const string LIVE_PHOTO_IDENTIFIER_PREFIX = 'live-photo:';

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
     * Strips an existing "-duplicate-NNN" suffix from a basename.
     * Uses proper regex escaping and end-of-string anchoring.
     *
     * @param string $basename Basename without extension
     */
    public static function stripDuplicateSuffix(string $basename): string
    {
        return preg_replace(
            '/' . preg_quote(self::DUPLICATE_IDENTIFIER, '/') . '\d+$/',
            '',
            $basename
        ) ?? $basename;
    }
}
