<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

use function array_key_exists;

/**
 * Projects output counters into the operator-facing summary rows rendered at
 * the end of rename and preview flows.
 *
 * The builder keeps the policy of "which counters become visible summary rows"
 * separate from the console rendering itself. That lets the renderer focus on
 * layout while this collaborator owns the conditional row selection and the
 * dry-run specific label switch.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class OutputSummaryRowBuilder
{
    /**
     * Builds the ordered summary rows for the given counters.
     *
     * Optional rows are included only when their counters are non-zero so the
     * summary stays compact. The final row switches between "Files processed"
     * and "Files to process" based on dry-run mode.
     *
     * @param array<string, int> $counters Runtime or preview counters keyed by their stable summary names.
     * @param bool               $dryRun   Whether the command is currently simulating changes.
     *
     * @return list<array{string, string}> Ordered label/value rows ready for aligned rendering.
     */
    public function build(array $counters, bool $dryRun): array
    {
        $rows = [
            ['Scanned files', (string) $counters['scannedFiles']],
        ];

        if ($counters['skippedCount'] > 0) {
            $rows[] = ['Skipped (no metadata)', (string) $counters['skippedCount']];
        }

        if ($counters['errorCount'] > 0) {
            $rows[] = ['Skipped (read errors)', (string) $counters['errorCount']];
        }

        if ($counters['plannedMoves'] > 0) {
            $rows[] = ['Planned moves', (string) $counters['plannedMoves']];
        }

        if ($counters['plannedSkips'] > 0) {
            $rows[] = ['Planned skips', (string) $counters['plannedSkips']];
        }

        if ($counters['livePhotoGroups'] > 0) {
            $rows[] = ['Live Photo groups', (string) $counters['livePhotoGroups']];
        }

        if ($counters['duplicateCount'] > 0) {
            $rows[] = ['Duplicates found', (string) $counters['duplicateCount']];
        }

        if ($counters['namingCollisions'] > 0) {
            $rows[] = ['Naming collisions', (string) $counters['namingCollisions']];
        }

        if (array_key_exists('crossGroupVideoReviewCount', $counters) && ($counters['crossGroupVideoReviewCount'] > 0)) {
            $rows[] = ['Cross-group video review', (string) $counters['crossGroupVideoReviewCount']];
        }

        $rows[] = [$dryRun ? 'Files to process' : 'Files processed', (string) $counters['fileCount']];

        return $rows;
    }
}
