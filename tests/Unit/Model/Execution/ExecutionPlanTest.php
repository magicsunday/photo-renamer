<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Execution;

use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ExecutionPlan aggregation methods: group/item counts,
 * filtering by Live Photo groups, rename-required items, and no-ops.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExecutionPlan::class)]
#[UsesClass(ExecutionGroup::class)]
#[UsesClass(ExecutionItem::class)]
final class ExecutionPlanTest extends TestCase
{
    /**
     * Verifies that groupCount() returns the total number of ExecutionGroups in the plan.
     */
    #[Test]
    public function groupCountReturnsCorrectValue(): void
    {
        $plan = new ExecutionPlan(
            groups: [
                $this->createGroup('g1', false, []),
                $this->createGroup('g2', false, []),
            ],
        );

        self::assertSame(2, $plan->groupCount());
    }

    /**
     * Verifies that livePhotoGroupCount() correctly filters and counts groups
     * that are identified as Live Photo pairs.
     */
    #[Test]
    public function livePhotoGroupCountFiltersCorrectly(): void
    {
        $plan = new ExecutionPlan(
            groups: [
                $this->createGroup('g1', true, []),
                $this->createGroup('g2', false, []),
            ],
        );

        self::assertSame(1, $plan->livePhotoGroupCount());
    }

    /**
     * Verifies that totalItemCount() returns the sum of all items across all groups.
     */
    #[Test]
    public function totalItemCountSumsAllGroups(): void
    {
        $plan = new ExecutionPlan(
            groups: [
                $this->createGroup('g1', false, [
                    $this->createItem(renameRequired: true, isNoOp: false),
                    $this->createItem(renameRequired: false, isNoOp: true),
                ]),
                $this->createGroup('g2', false, [
                    $this->createItem(renameRequired: true, isNoOp: false),
                ]),
            ],
        );

        self::assertSame(3, $plan->totalItemCount());
    }

    /**
     * Verifies that executableItemCount() counts only items that are marked as isExecutable.
     * Items are typically non-executable if their target path is already occupied.
     */
    #[Test]
    public function executableItemCountFiltersIsExecutable(): void
    {
        $plan = new ExecutionPlan(
            groups: [
                $this->createGroup('g1', false, [
                    $this->createItem(renameRequired: true, isNoOp: false, isExecutable: true),
                    $this->createItem(renameRequired: false, isNoOp: true, isExecutable: false),
                    $this->createItem(renameRequired: true, isNoOp: false, isExecutable: true),
                    $this->createItem(renameRequired: true, isNoOp: false, isExecutable: false),
                ]),
            ],
        );

        self::assertSame(2, $plan->executableItemCount());
    }

    /**
     * Verifies that nonExecutableItemCount() counts items that are blocked (isExecutable = false)
     * but excludes items that are already correctly named (isNoOp = true).
     */
    #[Test]
    public function nonExecutableItemCountExcludesNoOps(): void
    {
        $plan = new ExecutionPlan(
            groups: [
                $this->createGroup('g1', false, [
                    $this->createItem(renameRequired: true, isNoOp: false, isExecutable: true),
                    $this->createItem(renameRequired: false, isNoOp: true, isExecutable: false),
                    $this->createItem(renameRequired: true, isNoOp: false, isExecutable: false),
                ]),
            ],
        );

        // Only the 3rd item: non-executable AND not a no-op
        self::assertSame(1, $plan->nonExecutableItemCount());
    }

    /**
     * Verifies that noOpItemCount() correctly counts items where no action is needed
     * because they already match their target name.
     */
    #[Test]
    public function noOpItemCountFiltersNoOps(): void
    {
        $plan = new ExecutionPlan(
            groups: [
                $this->createGroup('g1', false, [
                    $this->createItem(renameRequired: true, isNoOp: false),
                    $this->createItem(renameRequired: false, isNoOp: true),
                    $this->createItem(renameRequired: false, isNoOp: true),
                ]),
            ],
        );

        self::assertSame(2, $plan->noOpItemCount());
    }

    /**
     * Verifies that a default/empty plan returns zero for all aggregation metrics.
     */
    #[Test]
    public function emptyPlanReturnsZeros(): void
    {
        $plan = new ExecutionPlan();

        self::assertSame(0, $plan->groupCount());
        self::assertSame(0, $plan->livePhotoGroupCount());
        self::assertSame(0, $plan->totalItemCount());
        self::assertSame(0, $plan->executableItemCount());
        self::assertSame(0, $plan->nonExecutableItemCount());
        self::assertSame(0, $plan->noOpItemCount());
    }

    /**
     * @param list<ExecutionItem> $items
     */
    private function createGroup(string $groupKey, bool $isLivePhoto, array $items): ExecutionGroup
    {
        return new ExecutionGroup(
            groupKey: $groupKey,
            isLivePhotoGroup: $isLivePhoto,
            items: $items,
        );
    }

    private function createItem(bool $renameRequired, bool $isNoOp, bool $isExecutable = true): ExecutionItem
    {
        return new ExecutionItem(
            sourcePath: '/photos/source.heic',
            targetPath: $renameRequired ? '/photos/target.heic' : '/photos/source.heic',
            type: ExecutionItemType::Canonical,
            renameRequired: $renameRequired,
            isNoOp: $isNoOp,
            groupKey: 'group-1',
            isExecutable: $isExecutable,
        );
    }
}
