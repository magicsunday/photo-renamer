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
