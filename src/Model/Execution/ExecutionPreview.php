<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Execution;

/**
 * Immutable result produced by the Renderer after iterating the ExecutionPlan.
 * Source of truth for plan-time counts (what was planned/displayed).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExecutionPreview
{
    public function __construct(
        public int $plannedMoves = 0,
        public int $plannedSkips = 0,
        public int $duplicateCount = 0,
    ) {
    }
}
