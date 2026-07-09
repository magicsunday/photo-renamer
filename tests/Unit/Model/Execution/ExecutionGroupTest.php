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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ExecutionGroup construction, item counting,
 * and type-based filtering.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExecutionGroup::class)]
#[UsesClass(ExecutionItem::class)]
final class ExecutionGroupTest extends TestCase
{
    /**
     * Verifies that the constructor correctly initializes all public properties.
     * The ExecutionGroup is a DTO representing a processed group in the execution plan.
     */
    #[Test]
    public function constructorSetsFields(): void
    {
        $group = new ExecutionGroup(
            groupKey: 'group-abc',
            isLivePhotoGroup: true,
            canonicalSourcePath: '/photos/canonical.heic',
            items: [],
            decisionLog: ['selected canonical by score'],
        );

        self::assertSame('group-abc', $group->groupKey);
        self::assertTrue($group->isLivePhotoGroup);
        self::assertSame('/photos/canonical.heic', $group->canonicalSourcePath);
        self::assertSame([], $group->items);
        self::assertSame(['selected canonical by score'], $group->decisionLog);
    }

    /**
     * Verifies that itemCount() returns the total number of items within the group.
     */
    #[Test]
    public function itemCountReturnsCorrectValue(): void
    {
        $items = [
            $this->createItem(ExecutionItemType::Canonical),
            $this->createItem(ExecutionItemType::Duplicate),
            $this->createItem(ExecutionItemType::Companion),
        ];

        $group = new ExecutionGroup(
            groupKey: 'group-1',
            isLivePhotoGroup: false,
            items: $items,
        );

        self::assertSame(3, $group->itemCount());
    }

    /**
     * Verifies that items can be filtered by their ExecutionItemType (Canonical, Duplicate, Companion).
     * This is used by the renderer to separate the primary file from its duplicates and sidecars.
     */
    #[Test]
    public function getItemsByTypeFiltersCorrectly(): void
    {
        $canonical  = $this->createItem(ExecutionItemType::Canonical);
        $duplicate1 = $this->createItem(ExecutionItemType::Duplicate);
        $duplicate2 = $this->createItem(ExecutionItemType::Duplicate);
        $companion  = $this->createItem(ExecutionItemType::Companion);

        $group = new ExecutionGroup(
            groupKey: 'group-1',
            isLivePhotoGroup: false,
            items: [$canonical, $duplicate1, $duplicate2, $companion],
        );

        $duplicates = $group->getItemsByType(ExecutionItemType::Duplicate);

        self::assertCount(2, $duplicates);
        self::assertSame($duplicate1, $duplicates[0]);
        self::assertSame($duplicate2, $duplicates[1]);
    }

    private function createItem(ExecutionItemType $type): ExecutionItem
    {
        return new ExecutionItem(
            sourcePath: '/photos/source.heic',
            targetPath: '/photos/target.heic',
            type: $type,
            renameRequired: true,
            isNoOp: false,
            groupKey: 'group-1',
        );
    }
}
