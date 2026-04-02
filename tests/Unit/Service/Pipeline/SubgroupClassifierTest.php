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
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
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
#[UsesClass(FileList::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(Rename::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class SubgroupClassifierTest extends TestCase
{
    private HashSubGroupingServiceInterface&MockObject $hashSubGroupingService;

    private BufferedOutput $output;

    private PerceptualHashCalculatorInterface $perceptualHashCalculator;

    private SubgroupClassifierInterface $classifier;

    protected function setUp(): void
    {
        $this->hashSubGroupingService   = $this->createMock(HashSubGroupingServiceInterface::class);
        $this->output                   = new BufferedOutput();
        $this->perceptualHashCalculator = self::createStub(PerceptualHashCalculatorInterface::class);
        $this->classifier               = new SubgroupClassifier(
            $this->hashSubGroupingService,
            new MediaTypeClassifier(),
            new OrphanLivePhotoVideoReconciler(
                new MediaTypeClassifier(),
                $this->perceptualHashCalculator,
                new SymfonyStyle(new ArrayInput([]), $this->output),
            ),
            new SymfonyStyle(new ArrayInput([]), $this->output),
        );
    }

    /**
     * Ensures that groups with only one item are skipped, as no sub-grouping
     * is necessary.
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
     * Verifies that items remain unchanged if the sub-grouping service
     * decides that no further splitting of the group is required.
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
            ->willReturn(null);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('clearCache');

        $this->classifier->classify($groups);

        self::assertNull($group->getItems()[0]->clusterId);
        self::assertNull($group->getItems()[1]->clusterId);
    }

    /**
     * Verifies that the cluster ID is correctly set on items when
     * subgrouping (e.g., via perceptual hashing) occurs.
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
            ->willReturn([
                '/photos/IMG_0001.jpg' => '000_abc123',
                '/photos/IMG_0002.jpg' => '002_def456',
            ]);

        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('clearCache');

        $this->classifier->classify($groups);

        $items = $group->getItems();
        self::assertSame('000_abc123', $items[0]->clusterId);
        self::assertSame('002_def456', $items[1]->clusterId);
    }

    /**
     * Ensures that the cache is cleared for each group to avoid side
     * effects between different asset groups.
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
            ->willReturn(null);

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
            ->willReturn([
                '/photos/IMG_0001.jpg' => '000_abc123',
                '/photos/IMG_0002.jpg' => '002_def456',
            ]);

        $this->classifier->classify($groups);

        // SubgroupClassifier only sets clusterIds; naming collision tracking is CollisionResolver's job
        $items = $group->getItems();
        self::assertSame('000_abc123', $items[0]->clusterId);
        self::assertSame('002_def456', $items[1]->clusterId);
    }

    /**
     * Verifies that the map of Content-IDs is correctly built from the items
     * in the group to identify duplicates within the group.
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
                ) use (&$capturedContentIdMap, &$capturedTemporalMap): ?array {
                    $capturedContentIdMap = $contentIdentifierMap;
                    $capturedTemporalMap  = $temporalMetadataMap;

                    return null;
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
     * Ensures that the group's status is correctly marked upon successful
     * classification.
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
            ->willReturn(null);

        $this->classifier->classify($groups);

        self::assertTrue($group->wasClassified());
        self::assertFalse($group->isClassificationDegraded());
    }

    /**
     * When apply() returns a cluster map and sub-grouping succeeds, the group
     * should be marked as classification succeeded.
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
            ->willReturn([
                '/photos/IMG_0001.jpg' => '000_abc123',
                '/photos/IMG_0002.jpg' => '002_def456',
            ]);

        $this->classifier->classify($groups);

        self::assertTrue($group->wasClassified());
        self::assertFalse($group->isClassificationDegraded());

        // ClusterIds should also be set
        $items = $group->getItems();
        self::assertSame('000_abc123', $items[0]->clusterId);
        self::assertSame('002_def456', $items[1]->clusterId);
    }

    /**
     * Verifies that the group's status is marked as "Degraded" if an exception
     * occurs during classification (e.g., faulty image data).
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
     * When apply() returns a cluster map, each item must have a clusterRank set
     * based on the order it appears within its cluster.
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

        // Items 1 and 2 in same cluster, item 3 in a different cluster
        $this->hashSubGroupingService
            ->expects(self::once())
            ->method('apply')
            ->willReturn([
                '/photos/IMG_0001.jpg' => '000_abc123',
                '/photos/IMG_0002.jpg' => '000_abc123',
                '/photos/IMG_0003.jpg' => '002_def456',
            ]);

        $this->classifier->classify($groups);

        $items = $group->getItems();

        // Item 1: canonical cluster, rank 0
        self::assertSame('000_abc123', $items[0]->clusterId);
        self::assertSame(0, $items[0]->clusterRank);

        // Item 2: canonical cluster, rank 1 (second in same cluster)
        self::assertSame('000_abc123', $items[1]->clusterId);
        self::assertSame(1, $items[1]->clusterRank);

        // Item 3: subgroup cluster, rank 0 (first in its cluster)
        self::assertSame('002_def456', $items[2]->clusterId);
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
