<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

/**
 * Shared keys for metadata issue states understood by multiple commands.
 *
 * Verify and write-date talk about overlapping metadata problems: missing
 * dates, fallback-only tags, timezone ambiguity, and excessive filename drift.
 * The human-facing labels differ per command, but the underlying identifiers
 * should stay aligned so filtering, planning, and fix suggestions cannot drift
 * apart over time.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class MetadataIssueKeyCatalog
{
    /**
     * Shared key for files without any usable capture date metadata.
     */
    public const string NODATA = 'nodata';

    /**
     * Shared key for files that only expose fallback date tags.
     */
    public const string FALLBACK = 'fallback';

    /**
     * Shared key for QuickTime files whose local timezone cannot be inferred safely.
     */
    public const string TIMEZONE = 'timezone';

    /**
     * Shared key for files whose metadata date drifts too far from the filename date.
     */
    public const string DRIFT = 'drift';
}
