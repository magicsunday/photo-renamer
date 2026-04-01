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
