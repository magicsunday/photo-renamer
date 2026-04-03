<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

use MagicSunday\Renamer\Model\OutputEntry;

/**
 * Immutable DTO describing the projected output entries for one renderer build pass.
 *
 * The renderer previously exposed a tuple of `[entries, skippedCount, errorCount]`
 * across legacy and execution-plan callers. This value object makes that
 * contract explicit and keeps the public output boundary aligned with the Wave 2
 * DTO policy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputEntryBuildResult
{
    /**
     * @param list<OutputEntry> $entries      Projected output entries ready for rendering
     * @param int               $skippedCount Number of skipped (non-error) files appended to the output
     * @param int               $errorCount   Number of error-tagged skipped files appended to the output
     */
    public function __construct(
        public array $entries,
        public int $skippedCount,
        public int $errorCount,
    ) {
    }
}
