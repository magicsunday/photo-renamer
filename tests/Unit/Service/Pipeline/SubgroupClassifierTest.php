<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use Closure;
use DateTimeImmutable;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies SubgroupClassifier delegates to HashSubGroupingService and maps results
 * back to AssetItem clusterIds within AssetGroups.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(SubgroupClassifier::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(Rename::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class SubgroupClassifierTest extends TestCase
{
    private HashSubGroupingServiceInterface&MockObject $hashSubGroupingService;

    private SubgroupClassifierInterface $classifier;

    protected function setUp(): void
    {
        $this->hashSubGroupingService = $this->createMock(HashSubGroupingServiceInterface::class);
        $this->classifier             = new SubgroupClassifier(
            $this->hashSubGroupingService,
            new MediaTypeClassifier(),
            new SymfonyStyle(new ArrayInput([]), new BufferedOutput()),
        );
    }

    /**
     * A group with only 1 item should not invoke apply() at all.
     */
    #[Test]
    public function singleItemGroupIsSkipped(): void
    {
        $item  = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::never())
            ->method('apply');

        $this->classifier->classify($groups);

        // Item should remain unchanged (no clusterId)
        self::assertNull($group->getItems()[0]->clusterId);
    }

    /**
     * When apply() returns false (no sub-grouping needed), items remain unchanged.
     */
    #[Test]
    public function noSubgroupingNeededLeavesItemsUnchanged(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturn(false);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('clearCache');

        $this->classifier->classify($groups);

        self::assertNull($group->getItems()[0]->clusterId);
        self::assertNull($group->getItems()[1]->clusterId);
    }

    /**
     * When apply() returns true and mutates renames with sub-group suffixes,
     * the corresponding AssetItems should receive clusterIds.
     */
    #[Test]
    public function subgroupingAppliedSetsClusterIdOnItems(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturnCallback(
                static function (FileDuplicate $fileDuplicate): bool {
                    // Simulate sub-grouping: mutate rename targets with sub-group suffixes
                    $renames = $fileDuplicate->getRenames();
                    $rename0 = $renames->get(0);
                    $rename1 = $renames->get(1);

                    if ($rename0 instanceof Rename) {
                        $rename0->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00.jpg'));
                    }

                    if ($rename1 instanceof Rename) {
                        $rename1->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00-002.jpg'));
                    }

                    return true;
                },
            );

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('clearCache');

        $this->classifier->classify($groups);

        $items = $group->getItems();
        self::assertSame('2024-01-01_12-00-00', $items[0]->clusterId);
        self::assertSame('2024-01-01_12-00-00-002', $items[1]->clusterId);
    }

    /**
     * clearCache() must be called after processing each group to release Imagick memory.
     */
    #[Test]
    public function clearCacheCalledPerGroup(): void
    {
        $group1 = new AssetGroup('group-1');
        $group1->addItem(new AssetItem(new SplFileInfo('/photos/a.jpg')));
        $group1->addItem(new AssetItem(new SplFileInfo('/photos/b.jpg')));

        $group2 = new AssetGroup('group-2');
        $group2->addItem(new AssetItem(new SplFileInfo('/photos/c.jpg')));
        $group2->addItem(new AssetItem(new SplFileInfo('/photos/d.jpg')));

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group1);
        $groups->set('group-2', $group2);

        $this->hashSubGroupingService
            ->method('apply')
            ->willReturn(false);

        $this->hashSubGroupingService
            ->expects(self::exactly(2))
            ->method('clearCache');

        $this->classifier->classify($groups);
    }

    /**
     * SubgroupClassifier detects content-distinct sub-groups but does NOT increment
     * the naming collision counter — that responsibility belongs to CollisionResolver.
     */
    #[Test]
    public function namingCollisionNotIncrementedBySubgroupClassifier(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturnCallback(
                static function (FileDuplicate $fileDuplicate): bool {
                    $renames = $fileDuplicate->getRenames();
                    $rename0 = $renames->get(0);
                    $rename1 = $renames->get(1);

                    if ($rename0 instanceof Rename) {
                        $rename0->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00.jpg'));
                    }

                    if ($rename1 instanceof Rename) {
                        $rename1->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00-002.jpg'));
                    }

                    return true;
                },
            );

        $this->classifier->classify($groups);

        // SubgroupClassifier only sets clusterIds; naming collision tracking is CollisionResolver's job
        $items = $group->getItems();
        self::assertSame('2024-01-01_12-00-00', $items[0]->clusterId);
        self::assertSame('2024-01-01_12-00-00-002', $items[1]->clusterId);
    }

    /**
     * The content identifier and temporal metadata maps passed to apply()
     * must be built from the AssetItems' properties.
     */
    #[Test]
    public function contentIdentifierMapBuiltFromItems(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-01 12:00:00'),
            'abc-123',
        );

        $item1 = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            metadata: $metadata,
            contentIdentifier: 'abc-123',
        );

        $item2 = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            metadata: null,
            contentIdentifier: 'abc-123',
        );

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        /** @var array<string, string>|null $capturedContentIdMap */
        $capturedContentIdMap = null;

        /** @var array<string, TemporalMetadata|null>|null $capturedTemporalMap */
        $capturedTemporalMap = null;

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturnCallback(
                static function (
                    FileDuplicate $fileDuplicate,
                    ?Rename $canonicalRename,
                    ?Rename $companionRename,
                    array $contentIdentifierMap,
                    Closure $targetPathnameResolver,
                    array $temporalMetadataMap,
                ) use (&$capturedContentIdMap, &$capturedTemporalMap): bool {
                    $capturedContentIdMap = $contentIdentifierMap;
                    $capturedTemporalMap  = $temporalMetadataMap;

                    return false;
                },
            );

        $this->classifier->classify($groups);

        self::assertIsArray($capturedContentIdMap);
        self::assertArrayHasKey('/photos/IMG_0001.heic', $capturedContentIdMap);
        self::assertSame('abc-123', $capturedContentIdMap['/photos/IMG_0001.heic']);
        self::assertArrayHasKey('/photos/IMG_0001.mov', $capturedContentIdMap);
        self::assertSame('abc-123', $capturedContentIdMap['/photos/IMG_0001.mov']);

        self::assertIsArray($capturedTemporalMap);
        self::assertArrayHasKey('/photos/IMG_0001.heic', $capturedTemporalMap);
        self::assertSame($metadata, $capturedTemporalMap['/photos/IMG_0001.heic']);
    }

    /**
     * An empty AssetGroupCollection should result in no calls to apply().
     */
    #[Test]
    public function emptyGroupCollectionIsNoOp(): void
    {
        $groups = new AssetGroupCollection();

        $this->hashSubGroupingService
            ->expects(self::never())
            ->method('apply');

        $this->hashSubGroupingService
            ->expects(self::never())
            ->method('clearCache');

        $this->classifier->classify($groups);

        // Verify no side effects occurred (groups remain empty)
        self::assertCount(0, $groups);
    }

    /**
     * When apply() returns false (single hash group), the group should be marked
     * as classification succeeded — not degraded.
     */
    #[Test]
    public function classificationMarkedSucceededOnNormalGroup(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturn(false);

        $this->classifier->classify($groups);

        self::assertTrue($group->wasClassified());
        self::assertFalse($group->isClassificationDegraded());
    }

    /**
     * When apply() returns true and sub-grouping succeeds, the group should be
     * marked as classification succeeded.
     */
    #[Test]
    public function classificationMarkedSucceededAfterSubgrouping(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturnCallback(
                static function (FileDuplicate $fileDuplicate): bool {
                    $renames = $fileDuplicate->getRenames();
                    $rename0 = $renames->get(0);
                    $rename1 = $renames->get(1);

                    if ($rename0 instanceof Rename) {
                        $rename0->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00.jpg'));
                    }

                    if ($rename1 instanceof Rename) {
                        $rename1->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00-002.jpg'));
                    }

                    return true;
                },
            );

        $this->classifier->classify($groups);

        self::assertTrue($group->wasClassified());
        self::assertFalse($group->isClassificationDegraded());

        // ClusterIds should also be set
        $items = $group->getItems();
        self::assertSame('2024-01-01_12-00-00', $items[0]->clusterId);
        self::assertSame('2024-01-01_12-00-00-002', $items[1]->clusterId);
    }

    /**
     * When apply() throws, the group should be marked as degraded and no items
     * should have clusterIds set (atomic rollback).
     */
    #[Test]
    public function classificationMarkedDegradedOnException(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willThrowException(new RuntimeException('Imagick failed to decode image'));

        $this->classifier->classify($groups);

        // Group should be marked as degraded
        self::assertTrue($group->wasClassified());
        self::assertTrue($group->isClassificationDegraded());
        self::assertSame('Imagick failed to decode image', $group->getClassificationFailureReason());

        // No items should have clusterIds (atomic guarantee)
        self::assertNull($group->getItems()[0]->clusterId);
        self::assertNull($group->getItems()[1]->clusterId);

        // Decision log should contain the failure reason
        $decisions = $group->getDecisionLog();
        self::assertNotEmpty($decisions);
        self::assertStringContainsString('Subgroup classification failed', $decisions[0]);
        self::assertStringContainsString('Imagick failed to decode image', $decisions[0]);
    }

    /**
     * When apply() returns true and sub-grouping succeeds, each item must have a
     * clusterRank set based on the order it appears within its cluster.
     */
    #[Test]
    public function classifyGroupSetsClusterRank(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));
        $item3 = new AssetItem(new SplFileInfo('/photos/IMG_0003.jpg'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);
        $group->addItem($item3);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturnCallback(
                static function (FileDuplicate $fileDuplicate): bool {
                    $renames = $fileDuplicate->getRenames();
                    $rename0 = $renames->get(0);
                    $rename1 = $renames->get(1);
                    $rename2 = $renames->get(2);

                    // Item 1 and 2 in canonical cluster, item 3 in subgroup -002
                    if ($rename0 instanceof Rename) {
                        $rename0->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00.jpg'));
                    }

                    if ($rename1 instanceof Rename) {
                        $rename1->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00-duplicate-001.jpg'));
                    }

                    if ($rename2 instanceof Rename) {
                        $rename2->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00-002.jpg'));
                    }

                    return true;
                },
            );

        $this->classifier->classify($groups);

        $items = $group->getItems();

        // Item 1: canonical cluster, rank 0
        self::assertSame('2024-01-01_12-00-00', $items[0]->clusterId);
        self::assertSame(0, $items[0]->clusterRank);

        // Item 2: canonical cluster (duplicate), rank 1
        self::assertSame('2024-01-01_12-00-00-duplicate-001', $items[1]->clusterId);
        self::assertSame(1, $items[1]->clusterRank);

        // Item 3: subgroup -002, rank 0 (first in its cluster)
        self::assertSame('2024-01-01_12-00-00-002', $items[2]->clusterId);
        self::assertSame(0, $items[2]->clusterRank);
    }

    /**
     * When apply() throws, clearCache() must still be called to release resources.
     */
    #[Test]
    public function clearCacheCalledEvenOnException(): void
    {
        $item1 = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item2 = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));

        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem($item1);
        $group->addItem($item2);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00', $group);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willThrowException(new RuntimeException('test error'));

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('clearCache');

        $this->classifier->classify($groups);

        // Verify group was marked degraded (exception was caught, not re-thrown)
        self::assertTrue($group->isClassificationDegraded());
    }
}
