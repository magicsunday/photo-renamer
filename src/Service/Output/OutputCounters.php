<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

/**
 * Planned presentation counts, independent of the selected output filter.
 * These are not observed execution successes; runtime results count actual moves.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputCounters
{
    /**
     * @param int $fileCount      Number of files planned for a move
     * @param int $duplicateCount Number of duplicate targets before output filtering
     * @param int $plannedMoves   Number of planned move operations
     * @param int $plannedSkips   Number of planned execution skips
     */
    public function __construct(
        public int $fileCount,
        public int $duplicateCount,
        public int $plannedMoves,
        public int $plannedSkips,
    ) {
    }
}
