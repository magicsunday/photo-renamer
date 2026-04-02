<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

/**
 * Central catalog for verify category identifiers and labels.
 *
 * The verify workflow uses stable category keys across scanning, filtering,
 * summary rendering, and Live Photo completeness reporting. Keeping those keys
 * in one focused catalog avoids scattered string literals without turning the
 * global constants file into an unrelated catch-all bucket.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class VerifyCategoryCatalog
{
    /**
     * Category for QuickTime timestamps whose local timezone cannot be inferred safely.
     */
    public const string TIMEZONE = 'timezone';

    /**
     * Category for metadata that only exposes fallback DateTime tags.
     */
    public const string FALLBACK = 'fallback';

    /**
     * Category for metadata dates that drift too far from the filename date.
     */
    public const string DRIFT = 'drift';

    /**
     * Category for missing Live Photo companions found in the second pass.
     */
    public const string LIVEPHOTO = 'livephoto';

    /**
     * Category for metadata extraction failures.
     */
    public const string ERROR = 'error';

    /**
     * Category for files without any usable capture metadata.
     */
    public const string NODATA = 'nodata';

    /**
     * Category for unsupported or unrecognized file types.
     */
    public const string FILETYPE = 'filetype';

    /**
     * Maps category keys to human-readable labels for command output.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        self::TIMEZONE  => 'Ambiguous timezone',
        self::FALLBACK  => 'No DateTimeOriginal',
        self::DRIFT     => 'Date drift',
        self::LIVEPHOTO => 'Missing Live Photo companion',
        self::ERROR     => 'Metadata read errors',
        self::NODATA    => 'No metadata',
        self::FILETYPE  => 'Unrecognized file types',
    ];
}
