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
 * Immutable result produced by the Executor after performing file operations.
 * Source of truth for runtime-only deltas/events (what actually happened).
 *
 * In dry-run mode, all values are zero (nothing executed).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExecutionResult
{
    public function __construct(
        public int $executedMoves = 0,
        public int $runtimeFallbacks = 0,
        public int $runtimeErrors = 0,
    ) {
    }
}
