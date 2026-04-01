<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies that CollisionResolver makes proposed names unique against the
 * disk index and already-planned targets, incrementing naming collisions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CollisionResolver::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(Constants::class)]
final class CollisionResolverTest extends TestCase
{
    /**
     * Free target is accepted as-is: proposedName unchanged, marked occupied.
     */
    #[Test]
    public function freeTargetIsAcceptedAsIs(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        $item  = $this->createItemWithProposal('/photos/IMG_0001.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $resolved = $group->getItems()[0];

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $resolved->proposedName);
        self::assertTrue($context->isOccupied('/photos/2024-01-01_12-00-00-000.heic'));
        self::assertSame(0, $context->getNamingCollisions());
    }

    /**
     * Occupied target gets incremented suffix: -duplicate-001.
     */
    #[Test]
    public function occupiedTargetGetsIncrementedSuffix(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        // Pre-occupy the target
        $context->markOccupied('/photos/2024-01-01_12-00-00-000.heic');

        $item  = $this->createItemWithProposal('/photos/IMG_0001.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $resolved = $group->getItems()[0];

        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $resolved->proposedName);
        self::assertTrue($context->isOccupied('/photos/2024-01-01_12-00-00-000-duplicate-001.heic'));
        self::assertSame(1, $context->getNamingCollisions());
    }

    /**
     * Multiple collisions increment correctly: -duplicate-001, -duplicate-002.
     */
    #[Test]
    public function multipleCollisionsIncrementCorrectly(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        $item1 = $this->createItemWithProposal('/photos/IMG_0001.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $item2 = $this->createItemWithProposal('/photos/IMG_0002.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $item3 = $this->createItemWithProposal('/photos/IMG_0003.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item1, $item2, $item3]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $items[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-002.heic', $items[2]->proposedName);
        self::assertSame(2, $context->getNamingCollisions());
    }

    /**
     * No-op file (source === proposed) is marked occupied but proposedName unchanged.
     */
    #[Test]
    public function noOpFileMarkedOccupiedButUnchanged(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        $item = $this->createItemWithProposal(
            '/photos/2024-01-01_12-00-00-000.heic',
            '/photos/2024-01-01_12-00-00-000.heic',
        );
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $resolved = $group->getItems()[0];

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $resolved->proposedName);
        self::assertTrue($context->isOccupied('/photos/2024-01-01_12-00-00-000.heic'));
        self::assertSame(0, $context->getNamingCollisions());
    }

    /**
     * Existing disk file blocks target: PipelineContext pre-populated causes collision.
     */
    #[Test]
    public function existingDiskFileBlocksTarget(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        // Simulate an existing file on disk
        $context->markOccupied('/photos/2024-01-01_12-00-00-000.heic');

        $item  = $this->createItemWithProposal('/photos/IMG_0001.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $resolved = $group->getItems()[0];

        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $resolved->proposedName);
        self::assertTrue($context->isOccupied('/photos/2024-01-01_12-00-00-000-duplicate-001.heic'));
    }

    /**
     * Cross-group collision detected: group A occupies target, group B item gets suffix.
     */
    #[Test]
    public function crossGroupCollisionDetected(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        $itemA  = $this->createItemWithProposal('/photos/IMG_0001.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $groupA = $this->createGroup('gA', [$itemA]);

        $itemB  = $this->createItemWithProposal('/photos/IMG_0002.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $groupB = $this->createGroup('gB', [$itemB]);

        $groups = new AssetGroupCollection();
        $groups->set('gA', $groupA);
        $groups->set('gB', $groupB);

        $resolver->resolve($groups, $context);

        $resolvedA = $groupA->getItems()[0];
        $resolvedB = $groupB->getItems()[0];

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $resolvedA->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $resolvedB->proposedName);
        self::assertSame(1, $context->getNamingCollisions());
    }

    /**
     * Naming collision counter is incremented for each collision resolved.
     */
    #[Test]
    public function namingCollisionCounterIncremented(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        // Pre-occupy the target so both items cause a collision
        $context->markOccupied('/photos/2024-01-01_12-00-00-000.heic');

        $item1 = $this->createItemWithProposal('/photos/IMG_0001.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $item2 = $this->createItemWithProposal('/photos/IMG_0002.heic', '/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item1, $item2]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        self::assertSame(2, $context->getNamingCollisions());
    }

    /**
     * Idempotent suffixed file stays unchanged: file already named -duplicate-001
     * and that candidate matches source path, so no rename needed.
     */
    #[Test]
    public function idempotentSuffixedFileStaysUnchanged(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        // The base target is occupied by another file
        $context->markOccupied('/photos/2024-01-01_12-00-00-000.heic');

        // This file is already at the -duplicate-001 path
        $item = $this->createItemWithProposal(
            '/photos/2024-01-01_12-00-00-000-duplicate-001.heic',
            '/photos/2024-01-01_12-00-00-000.heic',
        );
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $resolved = $group->getItems()[0];

        // The resolver should find that -duplicate-001 matches the source path (idempotent)
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $resolved->proposedName);
        self::assertFalse($resolved->renameRequired);
        self::assertTrue($context->isOccupied('/photos/2024-01-01_12-00-00-000-duplicate-001.heic'));
    }

    /**
     * Items with null proposedName are skipped entirely.
     */
    #[Test]
    public function nullProposedNameIsSkipped(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        $item  = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'));
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $resolved = $group->getItems()[0];

        self::assertNull($resolved->proposedName);
        self::assertSame(0, $context->getNamingCollisions());
    }

    /**
     * Group source paths are reclaimable, not collisions: when A.jpg targets
     * B.jpg and B.jpg is a source within the same group, the target should
     * NOT receive a -duplicate- suffix because B.jpg will be renamed away.
     */
    #[Test]
    public function groupSourcePathsAreReclaimableNotCollisions(): void
    {
        $resolver = new CollisionResolver();
        $context  = new PipelineContext('/photos');

        // Group with items: A.jpg → B.jpg, B.jpg → C.jpg
        // B.jpg is a source in the same group, so it's reclaimable
        $itemA = $this->createItemWithProposal('/photos/A.jpg', '/photos/B.jpg');
        $itemB = $this->createItemWithProposal('/photos/B.jpg', '/photos/C.jpg');
        $group = $this->createGroup('g1', [$itemA, $itemB]);

        // Pre-occupy B.jpg to simulate it existing on disk (it IS an existing file)
        $context->markOccupied('/photos/B.jpg');

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $resolver->resolve($groups, $context);

        $items = $group->getItems();

        // A.jpg targeting B.jpg should NOT get a -duplicate- suffix because B.jpg
        // is a source within the same group (will be renamed away to C.jpg)
        self::assertSame('/photos/B.jpg', $items[0]->proposedName);
        self::assertSame('/photos/C.jpg', $items[1]->proposedName);
        self::assertSame(0, $context->getNamingCollisions());
    }

    private function createItemWithProposal(string $sourcePath, string $proposedName): AssetItem
    {
        return new AssetItem(new SplFileInfo($sourcePath))
            ->withProposedName($proposedName);
    }

    /**
     * @param list<AssetItem> $items
     */
    private function createGroup(string $groupKey, array $items): AssetGroup
    {
        $group = new AssetGroup($groupKey);

        foreach ($items as $item) {
            $group->addItem($item);
        }

        return $group;
    }
}
