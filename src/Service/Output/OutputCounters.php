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
 * Immutable render counter DTO shared by output rendering and legacy execution.
 *
 * The output module needs a stable contract for counts such as planned moves,
 * skips, and duplicates. Returning this DTO instead of an associative array
 * keeps the contract explicit while allowing FileSystemService and
 * RenameOutputRenderer to share the same counter semantics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputCounters
{
    /**
     * @param int $fileCount      Number of files that will actually be processed
     * @param int $duplicateCount Number of visible duplicate targets
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
