<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Execution;

use function array_filter;
use function count;

/**
 * Top-level execution plan containing all groups to be processed.
 * Provides aggregate counts for rendering summaries and progress bars.
 *
 * Does NOT carry scan/analysis summary fields — those stay on
 * PipelineContext/RenameResult.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExecutionPlan
{
    /**
     * @param list<ExecutionGroup> $groups Execution groups to process
     */
    public function __construct(
        public array $groups = [],
    ) {
    }

    /**
     * Returns the total number of groups in the plan. Each group represents
     * one unique capture (potentially with companions and duplicates).
     *
     * @return int<0, max>
     */
    public function groupCount(): int
    {
        return count($this->groups);
    }

    /**
     * Returns the number of groups that contain Live Photo companions.
     * This is used to display specific statistics for Live Photo processing.
     *
     * @return int<0, max>
     */
    public function livePhotoGroupCount(): int
    {
        return count(
            array_filter(
                $this->groups,
                static fn (ExecutionGroup $group): bool => $group->isLivePhotoGroup,
            ),
        );
    }

    /**
     * Returns the total number of individual items across all groups.
     * Includes originals, companions, and all duplicates.
     *
     * @return int<0, max>
     */
    public function totalItemCount(): int
    {
        $count = 0;

        foreach ($this->groups as $group) {
            $count += $group->itemCount();
        }

        return $count;
    }

    /**
     * Returns the number of items that are actually eligible for movement or renaming.
     * This count excludes items that are already at the target or are blocked.
     *
     * @return int<0, max>
     */
    public function executableItemCount(): int
    {
        $count = 0;

        foreach ($this->groups as $group) {
            foreach ($group->items as $item) {
                if ($item->isExecutable) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * Returns the number of items that are NOT executable (blocked), excluding no-ops.
     * These are items that require attention (e.g., naming collisions).
     *
     * @return int<0, max>
     */
    public function nonExecutableItemCount(): int
    {
        $count = 0;

        foreach ($this->groups as $group) {
            foreach ($group->items as $item) {
                if (!$item->isExecutable && !$item->isNoOp) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * Returns the number of items that are already at their target location.
     * These items do not require any file operation.
     *
     * @return int<0, max>
     */
    public function noOpItemCount(): int
    {
        $count = 0;

        foreach ($this->groups as $group) {
            foreach ($group->items as $item) {
                if ($item->isNoOp) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
