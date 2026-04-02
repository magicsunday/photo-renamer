<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Execution;

use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the ExecutionItem DTO constructor, derived fields,
 * and relative path stripping behaviour.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExecutionItem::class)]
final class ExecutionItemTest extends TestCase
{
    /**
     * Verifies that the constructor correctly initializes all 12 properties
     * of the ExecutionItem DTO.
     */
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $item = new ExecutionItem(
            sourcePath: '/photos/IMG_0001.heic',
            targetPath: '/photos/2024-08-31_14-22-08.heic',
            type: ExecutionItemType::Canonical,
            renameRequired: true,
            isNoOp: false,
            groupKey: 'group-abc',
            clusterId: 'cluster-1',
            isDuplicateTarget: true,
            isLivePhotoConflict: true,
            isFallbackDate: true,
            isAmbiguousTimezone: true,
            warningReason: 'test warning',
        );

        self::assertSame('/photos/IMG_0001.heic', $item->sourcePath);
        self::assertSame('/photos/2024-08-31_14-22-08.heic', $item->targetPath);
        self::assertSame(ExecutionItemType::Canonical, $item->type);
        self::assertTrue($item->renameRequired);
        self::assertFalse($item->isNoOp);
        self::assertSame('group-abc', $item->groupKey);
        self::assertSame('cluster-1', $item->clusterId);
        self::assertTrue($item->isDuplicateTarget);
        self::assertTrue($item->isLivePhotoConflict);
        self::assertTrue($item->isFallbackDate);
        self::assertTrue($item->isAmbiguousTimezone);
        self::assertSame('test warning', $item->warningReason);
    }

    /**
     * Verifies that relativeSourcePath() correctly strips the given base directory
     * from the absolute source path.
     */
    #[Test]
    public function relativeSourcePathStripsBaseDirectory(): void
    {
        $item = new ExecutionItem(
            sourcePath: '/photos/a.jpg',
            targetPath: '/photos/b.jpg',
            type: ExecutionItemType::Canonical,
            renameRequired: true,
            isNoOp: false,
            groupKey: 'group-1',
        );

        self::assertSame('a.jpg', $item->relativeSourcePath('/photos'));
    }

    /**
     * Verifies that relativeTargetPath() correctly strips the given base directory
     * from the absolute target path.
     */
    #[Test]
    public function relativeTargetPathStripsBaseDirectory(): void
    {
        $item = new ExecutionItem(
            sourcePath: '/photos/a.jpg',
            targetPath: '/photos/sub/b.jpg',
            type: ExecutionItemType::Canonical,
            renameRequired: true,
            isNoOp: false,
            groupKey: 'group-1',
        );

        self::assertSame('sub/b.jpg', $item->relativeTargetPath('/photos'));
    }

    /**
     * Verifies that an item is marked as isNoOp (no operation) when the source
     * and target paths are identical, and that renameRequired is false in this case.
     */
    #[Test]
    public function isNoOpWhenSourceEqualsTarget(): void
    {
        $item = new ExecutionItem(
            sourcePath: '/photos/already-correct.heic',
            targetPath: '/photos/already-correct.heic',
            type: ExecutionItemType::Canonical,
            renameRequired: false,
            isNoOp: true,
            groupKey: 'group-1',
        );

        self::assertTrue($item->isNoOp);
        self::assertFalse($item->renameRequired);
    }

    /**
     * Verifies that renameRequired is true when source and target paths differ.
     */
    #[Test]
    public function renameRequiredWhenDifferentPaths(): void
    {
        $item = new ExecutionItem(
            sourcePath: '/photos/IMG_0001.heic',
            targetPath: '/photos/2024-08-31_14-22-08.heic',
            type: ExecutionItemType::Duplicate,
            renameRequired: true,
            isNoOp: false,
            groupKey: 'group-1',
        );

        self::assertTrue($item->renameRequired);
        self::assertFalse($item->isNoOp);
    }

    /**
     * Verifies that isExecutable defaults to true for new items.
     * An item remains executable unless blocked by a specific reason (e.g. target path occupied).
     */
    #[Test]
    public function isExecutableDefaultsToTrue(): void
    {
        $item = new ExecutionItem(
            sourcePath: '/photos/IMG_0001.heic',
            targetPath: '/photos/2024-08-31_14-22-08.heic',
            type: ExecutionItemType::Canonical,
            renameRequired: true,
            isNoOp: false,
            groupKey: 'group-1',
        );

        self::assertTrue($item->isExecutable);
    }

    /**
     * Verifies that executionBlockReason is null by default.
     */
    #[Test]
    public function executionBlockReasonDefaultsToNull(): void
    {
        $item = new ExecutionItem(
            sourcePath: '/photos/IMG_0001.heic',
            targetPath: '/photos/2024-08-31_14-22-08.heic',
            type: ExecutionItemType::Canonical,
            renameRequired: true,
            isNoOp: false,
            groupKey: 'group-1',
        );

        self::assertNull($item->executionBlockReason);
    }
}
