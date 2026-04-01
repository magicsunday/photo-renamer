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
     * Returns the total number of groups in the plan.
     */
    public function groupCount(): int
    {
        return count($this->groups);
    }

    /**
     * Returns the number of groups that contain Live Photo companions.
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
     * Returns the total number of items across all groups.
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
     * Returns the number of items where isExecutable is true.
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
     * Returns the number of non-executable items that are not no-ops
     * (i.e. items blocked from execution with a reason).
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
     * Returns the number of items where isNoOp is true.
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
