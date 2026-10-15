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
 * Immutable summary counters consumed by the rename output footer.
 *
 * The rename summary combines scan-time counts with output-rendering counters
 * and a small number of execution-plan specific review counters. This DTO
 * replaces the remaining associative-array contract at the summary boundary so
 * the renderer, summary-row builder, and legacy executor share one explicit
 * model.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameSummaryCounters
{
    /**
     * @param int $scannedFiles               Total files scanned for the run
     * @param int $skippedCount               Files skipped because no usable metadata was available
     * @param int $errorCount                 Files skipped due to read or processing errors
     * @param int $livePhotoGroups            Live Photo groups detected in the run
     * @param int $namingCollisions           Pre-execution naming collisions detected by validation
     * @param int $fileCount                  Files that would or did perform a rename operation
     * @param int $duplicateCount             Duplicate targets before display filtering
     * @param int $plannedMoves               Planned move operations before display filtering
     * @param int $plannedSkips               Planned execution skips before display filtering
     * @param int $crossGroupVideoReviewCount Cross-group video review entries detected during reconciliation
     * @param int $runtimeErrors              Failed file operations observed during execution
     * @param int $runtimeFallbacks           Successful moves whose actual target differs from the plan
     * @param int $partialLivePhotoGroups     Incomplete or separated Live Photo component groups after execution
     * @param int $unchangedCount             Files already at their requested path, excluding withheld entries
     */
    public function __construct(
        public int $scannedFiles,
        public int $skippedCount,
        public int $errorCount,
        public int $livePhotoGroups,
        public int $namingCollisions,
        public int $fileCount,
        public int $duplicateCount,
        public int $plannedMoves,
        public int $plannedSkips,
        public int $crossGroupVideoReviewCount = 0,
        public int $runtimeErrors = 0,
        public int $runtimeFallbacks = 0,
        public int $partialLivePhotoGroups = 0,
        public int $unchangedCount = 0,
    ) {
    }
}
