<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

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
    public const string PROGRESS_BAR_FORMAT = ' %current%/%max% [%bar%] %percent:3s%% | Elapsed: %elapsed% | Remaining: %remaining%';

    /**
     * Upper bound for the runtime duplicate suffix fallback loop.
     */
    public const int MAX_DUPLICATE_SUFFIX = 999;

    /**
     * Prefix used to identify Live Photo groups by their duplicate identifier string.
     */
    public const string LIVE_PHOTO_IDENTIFIER_PREFIX = 'live-photo:';

    /**
     * File extensions recognized as processable media files across all commands.
     *
     * @var list<string>
     */
    public const array SUPPORTED_MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'heic', 'heif', 'avi', 'mov', 'mp4', 'm4v'];

    /**
     * Default format priority for canonical selection (highest priority first).
     * Configurable via CANONICAL_FORMAT_PRIORITY env var.
     *
     * @var list<string>
     */
    public const array DEFAULT_FORMAT_PRIORITY = ['heic', 'heif', 'dng', 'arw', 'jpg', 'jpeg', 'mov', 'mp4', 'm4v', 'avi'];
}
