<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\WriteDate;

use function sprintf;

/**
 * Central catalog for write-date reason keys and their user-facing labels.
 *
 * The write-date workflow uses a stable set of reason identifiers for CLI
 * filtering, planning, output rendering, and summary aggregation. Keeping them
 * in one dedicated catalog avoids scattering string literals across commands
 * and analyzers while preserving the existing CLI semantics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class WriteDateReasonCatalog
{
    /**
     * Reason key for files with no metadata date at all.
     */
    public const string NODATA = 'nodata';

    /**
     * Reason key for files using only ModifyDate (0x0132) as fallback.
     */
    public const string FALLBACK = 'fallback';

    /**
     * Reason key for QuickTime files with ambiguous UTC timestamps.
     */
    public const string TIMEZONE = 'timezone';

    /**
     * Reason key for files whose metadata date differs significantly from filename date.
     */
    public const string DRIFT = 'drift';

    /**
     * Maps reason keys to their default human-readable labels.
     *
     * The drift label is a format string because it needs the computed day count.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        self::NODATA   => 'no date in metadata',
        self::FALLBACK => 'only ModifyDate (0x0132), no DateTimeOriginal',
        self::TIMEZONE => 'QuickTime timestamp without timezone info',
        self::DRIFT    => 'metadata date differs by %d days',
    ];

    /**
     * Formats the display label for a reason key.
     *
     * Drift reasons optionally carry a concrete day count. All other reasons
     * use their static labels unchanged.
     *
     * @param string   $reasonKey Reason key that should be rendered for humans
     * @param int|null $driftDays Drift in calendar days when the reason is drift
     *
     * @return string Human-readable label for output and summaries
     */
    public static function formatLabel(string $reasonKey, ?int $driftDays = null): string
    {
        $label = self::LABELS[$reasonKey] ?? $reasonKey;

        if (($reasonKey === self::DRIFT) && ($driftDays !== null)) {
            return sprintf($label, $driftDays);
        }

        return $label;
    }
}
