<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Execution;

use InvalidArgumentException;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Redundant path/rename/no-op flags and contradictory block reasons cannot
     * reach rendering or execution as apparently valid immutable plan entries.
     *
     * @param string      $target     Target path defining equality with the fixed source
     * @param bool        $rename     Requested mutation flag
     * @param bool        $noOp       Requested preservation flag
     * @param bool        $executable Whether mutation is permitted
     * @param string|null $reason     Block reason incompatible with the given state
     *
     * @return void
     */
    #[Test]
    #[DataProvider('contradictoryStates')]
    public function contradictoryStatesAreRejected(string $target, bool $rename, bool $noOp, bool $executable, ?string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionItem('/synthetic/a.jpg', $target, ExecutionItemType::Canonical, $rename, $noOp, 'synthetic', isExecutable: $executable, executionBlockReason: $reason);
    }

    /**
     * Covers contradictory flag combinations and reasons on executable/no-op entries.
     *
     * @return iterable<string, array{string, bool, bool, bool, string|null}> Invalid states
     */
    public static function contradictoryStates(): iterable
    {
        yield 'same path marked rename' => ['/synthetic/a.jpg', true, false, true, null];
        yield 'same path missing no-op' => ['/synthetic/a.jpg', false, false, false, null];
        yield 'different path marked no-op' => ['/synthetic/b.jpg', false, true, false, null];
        yield 'both rename and no-op' => ['/synthetic/b.jpg', true, true, false, null];
        yield 'neither rename nor no-op' => ['/synthetic/b.jpg', false, false, false, null];
        yield 'executable with block reason' => ['/synthetic/b.jpg', true, false, true, 'blocked'];
        yield 'no-op with block reason' => ['/synthetic/a.jpg', false, true, false, 'blocked'];
    }

    /**
     * Executable and withheld renames retain their policy state. Equal-path
     * no-ops always become non-executable, including callers using the default
     * true input. A blocked rename may omit an explanation, preserving the
     * existing fail-closed API without inventing a new policy requirement.
     *
     * @param bool        $rename              Whether paths differ
     * @param bool        $requestedExecutable Input execution permission
     * @param string|null $reason              Optional withheld-rename explanation
     * @param bool        $expectedExecutable  Effective permission after no-op derivation
     *
     * @return void
     */
    #[Test]
    #[DataProvider('validStates')]
    public function validStatesPreservePolicyAndNeverExecuteNoOps(bool $rename, bool $requestedExecutable, ?string $reason, bool $expectedExecutable): void
    {
        $target = $rename ? '/synthetic/b.jpg' : '/synthetic/a.jpg';
        $item   = new ExecutionItem('/synthetic/a.jpg', $target, ExecutionItemType::Canonical, $rename, !$rename, 'synthetic', isExecutable: $requestedExecutable, executionBlockReason: $reason);
        self::assertSame($rename, $item->renameRequired);
        self::assertSame(!$rename, $item->isNoOp);
        self::assertSame($expectedExecutable, $item->isExecutable);
        self::assertSame($reason, $item->executionBlockReason);
    }

    /**
     * Enumerates executable, blocked-with/without-reason and explicit/default no-op states.
     *
     * @return iterable<string, array{bool, bool, string|null, bool}> Valid states
     */
    public static function validStates(): iterable
    {
        yield 'executable rename' => [true, true, null, true];
        yield 'blocked with reason' => [true, false, 'classification unavailable', false];
        yield 'blocked without reason' => [true, false, null, false];
        yield 'explicit no-op' => [false, false, null, false];
        yield 'default-permission no-op' => [false, true, null, false];
    }

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
