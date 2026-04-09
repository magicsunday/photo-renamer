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
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Service\Pipeline\FlatGroupNameResolver;
use MagicSunday\Renamer\Service\Pipeline\SubgroupNameResolver;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;
use MagicSunday\Renamer\Test\Fixtures\TargetNameResolverFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the semantic naming logic of the TargetNameResolver.
 *
 * This test suite ensures that asset groups are correctly renamed based on their
 * role (Canonical, Companion, Duplicate, Subgroup) and the group key (usually
 * a timestamp). It covers:
 * - Simple renaming (canonical, companions, duplicates)
 * - Subgroup numbering (clusters of similar files)
 * - Idempotency (preventing unnecessary re-naming of already correct files)
 * - Stability of naming across dry-runs or partial executions
 *
 * The resolver only proposes names; it does not perform physical disk checks for
 * collisions with existing unrelated files (that is handled by CollisionResolver).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TargetNameResolver::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(Constants::class)]
#[UsesClass(FlatGroupNameResolver::class)]
#[UsesClass(SubgroupNameResolver::class)]
#[UsesClass(TargetNameResolverFactory::class)]
final class TargetNameResolverTest extends TestCase
{
    /**
     * Ensures that the main file (canonical) receives a clean filename without
     * suffixes (except for the date pattern).
     */
    #[Test]
    public function canonicalGetsCleanBasename(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $item  = $this->createItem('/photos/IMG_0001.heic', ItemRole::Canonical);
        $group = $this->createGroup('2024-01-01_12-00-00-000', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        $resolved = $group->getItems()[0];

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $resolved->proposedName);
        self::assertTrue($resolved->renameRequired);
    }

    /**
     * Verifies that companion files (e.g., sidecars) receive the same basename
     * as the main file but keep their own file extension.
     */
    #[Test]
    public function companionGetsSameBasenameWithOwnExtension(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical = $this->createItem('/photos/IMG_0001.heic', ItemRole::Canonical);
        $companion = $this->createItem('/photos/IMG_0001.mov', ItemRole::Companion);
        $group     = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $companion]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000.mov', $items[1]->proposedName);
    }

    /**
     * Verifies that duplicate files receive a sequential suffix (e.g., -duplicate-001)
     * to avoid name collisions within the group.
     */
    #[Test]
    public function duplicateGetsSequentialSuffix(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical  = $this->createItem('/photos/IMG_0001.heic', ItemRole::Canonical);
        $duplicate1 = $this->createItem('/photos/IMG_0002.heic', ItemRole::Duplicate);
        $duplicate2 = $this->createItem('/photos/IMG_0003.heic', ItemRole::Duplicate);
        $group      = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $duplicate1, $duplicate2]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $items[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-002.heic', $items[2]->proposedName);
    }

    /**
     * Ambiguous items get duplicate-style naming.
     */
    #[Test]
    public function ambiguousGetsDuplicateStyleSuffix(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical = $this->createItem('/photos/IMG_0001.heic', ItemRole::Canonical);
        $ambiguous = $this->createItem('/photos/IMG_0002.heic', ItemRole::Ambiguous);
        $group     = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $ambiguous]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $items[1]->proposedName);
    }

    /**
     * Ensures that files that already carry the correct name are marked
     * as "No-Op" (renameRequired = false).
     */
    #[Test]
    public function alreadyCorrectFileHasRenameRequiredFalse(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $item  = $this->createItem('/photos/2024-01-01_12-00-00-000.heic', ItemRole::Canonical);
        $group = $this->createGroup('2024-01-01_12-00-00-000', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        $resolved = $group->getItems()[0];

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $resolved->proposedName);
        self::assertFalse($resolved->renameRequired);
    }

    /**
     * Verifies that files remain within their original directories and only
     * the filename is adjusted.
     */
    #[Test]
    public function filesStayInTheirOwnDirectory(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical = $this->createItem('/photos/2024/IMG_0001.heic', ItemRole::Canonical);
        $duplicate = $this->createItem('/photos/2025/IMG_0002.heic', ItemRole::Duplicate);
        $group     = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $duplicate]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertSame('/photos/2024/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2025/2024-01-01_12-00-00-000-duplicate-001.heic', $items[1]->proposedName);
    }

    /**
     * With useFileExtensionFromSource = true, JPG stays .jpg even if canonical is HEIC.
     */
    #[Test]
    public function useFileExtensionFromSourcePreservesExtension(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical = $this->createItem('/photos/IMG_0001.heic', ItemRole::Canonical);
        $duplicate = $this->createItem('/photos/IMG_0002.JPG', ItemRole::Duplicate);
        $group     = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $duplicate]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups, useFileExtensionFromSource: true);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.jpg', $items[1]->proposedName);
    }

    /**
     * Sequence numbers are set on duplicate and ambiguous items.
     */
    #[Test]
    public function sequenceNumberSetOnDuplicates(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical  = $this->createItem('/photos/IMG_0001.heic', ItemRole::Canonical);
        $duplicate1 = $this->createItem('/photos/IMG_0002.heic', ItemRole::Duplicate);
        $duplicate2 = $this->createItem('/photos/IMG_0003.heic', ItemRole::Duplicate);
        $ambiguous  = $this->createItem('/photos/IMG_0004.heic', ItemRole::Ambiguous);
        $group      = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $duplicate1, $duplicate2, $ambiguous]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertNull($items[0]->sequenceNumber);
        self::assertSame(1, $items[1]->sequenceNumber);
        self::assertSame(2, $items[2]->sequenceNumber);
        self::assertSame(3, $items[3]->sequenceNumber);
    }

    /**
     * Empty group (no items) is skipped without error.
     */
    #[Test]
    public function emptyGroupIsSkipped(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $group = new AssetGroup('2024-01-01_12-00-00-000');

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups);

        self::assertSame([], $group->getItems());
    }

    /**
     * Verifies that items in subgroups receive a corresponding subgroup suffix
     * (e.g. "-002") if the group was split by perceptual hashing.
     */
    #[Test]
    public function subgroupItemsGetSubgroupSuffix(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);
        $duplicate = $this->createItemWithCluster('/photos/IMG_0002.heic', ItemRole::Duplicate, $canonicalCluster);
        $edit      = $this->createItemWithCluster('/photos/IMG_0003.jpg', ItemRole::Duplicate, $otherCluster);

        $group = $this->createGroup($groupKey, [$canonical, $duplicate, $edit]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $items[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $items[2]->proposedName);
    }

    /**
     * Items with the same clusterId as the canonical get no subgroup suffix.
     */
    #[Test]
    public function canonicalClusterGetsCleanBasename(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical  = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);
        $duplicate1 = $this->createItemWithCluster('/photos/IMG_0002.heic', ItemRole::Duplicate, $canonicalCluster);
        $duplicate2 = $this->createItemWithCluster('/photos/IMG_0003.heic', ItemRole::Duplicate, $canonicalCluster);
        $edit       = $this->createItemWithCluster('/photos/IMG_0004.jpg', ItemRole::Duplicate, $otherCluster);

        $group = $this->createGroup($groupKey, [$canonical, $duplicate1, $duplicate2, $edit]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Canonical cluster items: clean basename + duplicate suffixes
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $items[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-002.heic', $items[2]->proposedName);
        // Other cluster: subgroup suffix only
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $items[3]->proposedName);
    }

    /**
     * Verifies that multiple subgroups (clusters) within the same group
     * receive deterministic sequential suffixes (e.g., -002, -003).
     */
    #[Test]
    public function multipleSubgroupsGetSequentialNumbers(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;
        $cluster2         = $groupKey . '-002';
        $cluster3         = $groupKey . '-003';

        $canonical = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);
        $edit1     = $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $cluster2);
        $edit2     = $this->createItemWithCluster('/photos/IMG_0003.jpg', ItemRole::Duplicate, $cluster3);

        $group = $this->createGroup($groupKey, [$canonical, $edit1, $edit2]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $items[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-003.jpg', $items[2]->proposedName);
    }

    /**
     * Two items in the same non-canonical cluster: first gets -002, second gets -002-duplicate-001.
     */
    #[Test]
    public function duplicatesWithinSubgroupGetDuplicateSuffix(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);
        $edit1     = $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $otherCluster);
        $edit2     = $this->createItemWithCluster('/photos/IMG_0003.jpg', ItemRole::Duplicate, $otherCluster);

        $group = $this->createGroup($groupKey, [$canonical, $edit1, $edit2]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $items[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002-duplicate-001.jpg', $items[2]->proposedName);
    }

    /**
     * Non-canonical cluster item alone in a cross-directory must retain its subgroup
     * suffix (-002) for idempotency — the cross-directory no-conflict shortcut only
     * applies to canonical-cluster items.
     */
    #[Test]
    public function crossDirectoryNonCanonicalClusterKeepsSubgroupSuffix(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);
        $edit      = $this->createItemWithCluster('/photos/edited/IMG_0001.jpg', ItemRole::Duplicate, $otherCluster);

        $group = $this->createGroup($groupKey, [$canonical, $edit]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Canonical in /photos keeps clean name
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);

        // Edited JPG in /photos/edited is alone in its directory but must keep -002 suffix
        self::assertSame('/photos/edited/2024-01-01_12-00-00-000-002.jpg', $items[1]->proposedName);
    }

    /**
     * Canonical HEIC in root directory and a same-group duplicate JPG in a subdirectory:
     * both files stay in their own directories after naming. Uses useFileExtensionFromSource
     * so the JPG retains its own extension (otherwise the canonical extension is used).
     */
    #[Test]
    public function canonicalInRootDuplicateInSubdirPreservesDirectories(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical = $this->createItem('/photos/IMG_0001.heic', ItemRole::Canonical);
        $duplicate = $this->createItem('/photos/backup/IMG_0002.jpg', ItemRole::Duplicate);
        $group     = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $duplicate]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups, useFileExtensionFromSource: true);

        $items = $group->getItems();

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/backup/2024-01-01_12-00-00-000-duplicate-001.jpg', $items[1]->proposedName);
    }

    /**
     * Canonical HEIC in a subdirectory and a non-canonical JPG in the root:
     * both files stay in their own directories after naming. Uses useFileExtensionFromSource
     * so the JPG retains its own extension (otherwise the canonical extension is used).
     */
    #[Test]
    public function canonicalInSubdirNonCanonicalInRootPreservesDirectories(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $canonical = $this->createItem('/photos/originals/IMG_0001.heic', ItemRole::Canonical);
        $duplicate = $this->createItem('/photos/IMG_0002.jpg', ItemRole::Duplicate);
        $group     = $this->createGroup('2024-01-01_12-00-00-000', [$canonical, $duplicate]);

        $groups = new AssetGroupCollection();
        $groups->set('2024-01-01_12-00-00-000', $group);

        $resolver->resolve($groups, useFileExtensionFromSource: true);

        $items = $group->getItems();

        self::assertSame('/photos/originals/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.jpg', $items[1]->proposedName);
    }

    /**
     * Subgroup suffix is stable across directories: an edited JPG in a different directory
     * from the canonical must retain its subgroup suffix (-002) even though it is the only
     * group member in that directory.
     */
    #[Test]
    public function subgroupSuffixStableAcrossDirectories(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);
        $edit      = $this->createItemWithCluster('/photos/edits/IMG_0001.jpg', ItemRole::Duplicate, $otherCluster);

        $group = $this->createGroup($groupKey, [$canonical, $edit]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Canonical HEIC in /photos keeps clean name
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);

        // Edited JPG in /photos/edits must keep -002 suffix despite being alone in its directory
        self::assertSame('/photos/edits/2024-01-01_12-00-00-000-002.jpg', $items[1]->proposedName);
    }

    /**
     * When SubgroupClassifier partially fails (some items have clusterId, others null),
     * subgroup naming is triggered and unclassified items form an implicit separate cluster.
     */
    #[Test]
    public function partialClusterIdAssignmentTriggersSubgroupNaming(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey = '2024-01-01_12-00-00-000';
        $cluster  = $groupKey;

        // Item A has a clusterId, items B and C have null (partial classification failure)
        $itemA = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $cluster);
        $itemB = $this->createItem('/photos/IMG_0002.heic', ItemRole::Duplicate);
        $itemC = $this->createItem('/photos/IMG_0003.heic', ItemRole::Duplicate);

        $group = $this->createGroup($groupKey, [$itemA, $itemB, $itemC]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Item A (canonical, classified) gets clean name
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);

        // Items B and C (unclassified) form implicit subgroup -002
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.heic', $items[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002-duplicate-001.heic', $items[2]->proposedName);
    }

    /**
     * When an item already named with the clean subgroup basename (e.g. "key-002.jpg")
     * appears AFTER another item from the same cluster in iteration order, the
     * already-correct item must still receive the clean name (idempotency). Without
     * the idempotency sort, iteration order determines which item gets the clean name,
     * causing needless renames on re-runs.
     */
    #[Test]
    public function subgroupIdempotencyPrefersAlreadyCleanName(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_21-53-58-997';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        // Canonical in its own cluster
        $canonical = $this->createItemWithCluster(
            '/photos/2024-01-01_21-53-58-997.heic',
            ItemRole::Canonical,
            $canonicalCluster,
        );

        // Duplicate appears FIRST in iteration order but has a duplicate-suffixed name
        $dupItem = $this->createItemWithCluster(
            '/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg',
            ItemRole::Duplicate,
            $otherCluster . Constants::DUPLICATE_IDENTIFIER . '001',
        );

        // Clean-named item appears SECOND but already has the correct subgroup name
        $cleanItem = $this->createItemWithCluster(
            '/photos/2024-01-01_21-53-58-997-002.jpg',
            ItemRole::Duplicate,
            $otherCluster,
        );

        // Items added in "wrong" order: duplicate-suffixed first, clean second
        $group = $this->createGroup($groupKey, [$canonical, $dupItem, $cleanItem]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Canonical keeps clean name
        self::assertSame(
            '/photos/2024-01-01_21-53-58-997.heic',
            $items[0]->proposedName,
        );

        // The item that was already correctly named keeps its clean subgroup name
        $cleanResolved = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002.jpg');
        self::assertNotNull($cleanResolved);
        self::assertSame(
            '/photos/2024-01-01_21-53-58-997-002.jpg',
            $cleanResolved->proposedName,
        );
        self::assertFalse($cleanResolved->renameRequired);

        // The duplicate-suffixed item keeps its duplicate suffix
        $dupResolved = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg');
        self::assertNotNull($dupResolved);
        self::assertSame(
            '/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg',
            $dupResolved->proposedName,
        );
        self::assertFalse($dupResolved->renameRequired);
    }

    /**
     * Verifies idempotency for subgroups: files that already carry a
     * matching name should keep it to avoid unnecessary renames.
     */
    #[Test]
    public function subgroupIdempotencyAlreadyCorrectlyNamedFilesRemainNoOp(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_21-53-58-997';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        // Canonical already correctly named
        $canonical = $this->createItemWithCluster(
            '/photos/2024-01-01_21-53-58-997.heic',
            ItemRole::Canonical,
            $canonicalCluster,
        );

        // Subgroup items already correctly named — added in ALPHABETICAL order
        $dupItem = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg',
            ItemRole::Duplicate,
            $otherCluster . Constants::DUPLICATE_IDENTIFIER . '001',
            1,
        );

        $cleanItem = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002.jpg',
            ItemRole::Duplicate,
            $otherCluster,
            0,
        );

        // Alphabetical order: -002-duplicate-001 comes before -002 (because 'd' < '.')
        $group = $this->createGroup($groupKey, [$canonical, $dupItem, $cleanItem]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        // All items should be no-ops
        $canonicalResolved = $group->getItemByPath('/photos/2024-01-01_21-53-58-997.heic');
        self::assertNotNull($canonicalResolved);
        self::assertFalse($canonicalResolved->renameRequired);

        $cleanResolved = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002.jpg');
        self::assertNotNull($cleanResolved);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002.jpg', $cleanResolved->proposedName);
        self::assertFalse($cleanResolved->renameRequired);

        $dupResolved = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg');
        self::assertNotNull($dupResolved);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg', $dupResolved->proposedName);
        self::assertFalse($dupResolved->renameRequired);
    }

    /**
     * Items with existing -duplicate-001 and -duplicate-002 suffixes must preserve
     * their numbers without swapping.
     */
    #[Test]
    public function subgroupDuplicateNumbersRemainStable(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_21-53-58-997';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical = $this->createItemWithCluster(
            '/photos/2024-01-01_21-53-58-997.heic',
            ItemRole::Canonical,
            $canonicalCluster,
        );

        $cleanItem = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002.jpg',
            ItemRole::Duplicate,
            $otherCluster,
            0,
        );

        $dup1 = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg',
            ItemRole::Duplicate,
            $otherCluster . Constants::DUPLICATE_IDENTIFIER . '001',
            1,
        );

        $dup2 = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002-duplicate-002.jpg',
            ItemRole::Duplicate,
            $otherCluster . Constants::DUPLICATE_IDENTIFIER . '002',
            2,
        );

        // Alphabetical order
        $group = $this->createGroup($groupKey, [$canonical, $dup1, $dup2, $cleanItem]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $clean = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002.jpg');
        self::assertNotNull($clean);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002.jpg', $clean->proposedName);

        $d1 = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg');
        self::assertNotNull($d1);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg', $d1->proposedName);

        $d2 = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002-duplicate-002.jpg');
        self::assertNotNull($d2);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002-duplicate-002.jpg', $d2->proposedName);
    }

    /**
     * When -002-duplicate-001.jpg comes alphabetically BEFORE -002.jpg, the clean-named
     * item must still receive the clean subgroup name (P1 idempotent match).
     */
    #[Test]
    public function subgroupAlphabeticallyEarlierDuplicateDoesNotSwapWithCleanName(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_21-53-58-997';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical = $this->createItemWithCluster(
            '/photos/2024-01-01_21-53-58-997.heic',
            ItemRole::Canonical,
            $canonicalCluster,
        );

        // Alphabetically earlier: -002-duplicate-001 < -002 (d < .)
        $dupItem = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg',
            ItemRole::Duplicate,
            $otherCluster . Constants::DUPLICATE_IDENTIFIER . '001',
            1,
        );

        $cleanItem = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002.jpg',
            ItemRole::Duplicate,
            $otherCluster,
            0,
        );

        // Simulate CaptureGroupBuilder alphabetical order
        $group = $this->createGroup($groupKey, [$canonical, $dupItem, $cleanItem]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        // Clean-named item keeps its clean name (P1)
        $cleanResolved = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002.jpg');
        self::assertNotNull($cleanResolved);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002.jpg', $cleanResolved->proposedName);
        self::assertFalse($cleanResolved->renameRequired);

        // Duplicate-suffixed item keeps its name
        $dupResolved = $group->getItemByPath('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg');
        self::assertNotNull($dupResolved);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg', $dupResolved->proposedName);
        self::assertFalse($dupResolved->renameRequired);
    }

    /**
     * When no item in a subgroup matches the clean name, clusterRank determines
     * which item gets the clean name (rank 0) and which gets -duplicate-001 (rank 1).
     */
    #[Test]
    public function subgroupWithoutIdempotentMatchUsesClusterRank(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_21-53-58-997';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        $canonical = $this->createItemWithCluster(
            '/photos/2024-01-01_21-53-58-997.heic',
            ItemRole::Canonical,
            $canonicalCluster,
        );

        // Neither item matches the clean subgroup name — original filenames
        $itemA = $this->createItemWithClusterAndRank(
            '/photos/IMG_0001.jpg',
            ItemRole::Duplicate,
            $otherCluster,
            0, // rank 0 = gets clean name
        );

        $itemB = $this->createItemWithClusterAndRank(
            '/photos/IMG_0002.jpg',
            ItemRole::Duplicate,
            $otherCluster . Constants::DUPLICATE_IDENTIFIER . '001',
            1, // rank 1 = gets -duplicate-001
        );

        $group = $this->createGroup($groupKey, [$canonical, $itemA, $itemB]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $resolvedA = $group->getItemByPath('/photos/IMG_0001.jpg');
        self::assertNotNull($resolvedA);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002.jpg', $resolvedA->proposedName);

        $resolvedB = $group->getItemByPath('/photos/IMG_0002.jpg');
        self::assertNotNull($resolvedB);
        self::assertSame('/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg', $resolvedB->proposedName);
    }

    /**
     * Simulates the state AFTER a first rename has been applied: all items already
     * have their target names. A second dry-run must produce fully idempotent results
     * (all items are [O], no renames required).
     */
    #[Test]
    public function secondDryRunAfterSubgroupRenameIsFullyIdempotent(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_21-53-58-997';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        // After first rename: all files have their target names
        $canonical = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997.heic',
            ItemRole::Canonical,
            $canonicalCluster,
            0,
        );

        $subgroupClean = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002.jpg',
            ItemRole::Duplicate,
            $otherCluster,
            0,
        );

        $subgroupDup = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_21-53-58-997-002-duplicate-001.jpg',
            ItemRole::Duplicate,
            $otherCluster . Constants::DUPLICATE_IDENTIFIER . '001',
            1,
        );

        // Alphabetical order: -002-duplicate-001 before -002
        $group = $this->createGroup($groupKey, [$canonical, $subgroupDup, $subgroupClean]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        // Every item must be a no-op
        foreach ($group->getItems() as $item) {
            self::assertFalse(
                $item->renameRequired,
                sprintf(
                    'Item %s should not require rename but got proposedName=%s',
                    $item->file->getPathname(),
                    $item->proposedName ?? '(null)',
                ),
            );
        }
    }

    /**
     * Canonical cluster with duplicates already named -duplicate-001 and -duplicate-002:
     * items added in REVERSE order must keep their existing duplicate numbers without swapping.
     */
    #[Test]
    public function canonicalClusterDuplicateNumbersRemainStable(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey = '2024-01-01_12-00-00-000';
        $cluster  = $groupKey;

        $canonical = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_12-00-00-000.heic',
            ItemRole::Canonical,
            $cluster,
            0,
        );

        // Items added in REVERSE order: -duplicate-002 before -duplicate-001
        $dup2 = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_12-00-00-000-duplicate-002.heic',
            ItemRole::Duplicate,
            $cluster . Constants::DUPLICATE_IDENTIFIER . '002',
            2,
        );

        $dup1 = $this->createItemWithClusterAndRank(
            '/photos/2024-01-01_12-00-00-000-duplicate-001.heic',
            ItemRole::Duplicate,
            $cluster . Constants::DUPLICATE_IDENTIFIER . '001',
            1,
        );

        // Need a second cluster to trigger subgroup-aware path
        $otherCluster = $groupKey . '-002';
        $edit         = $this->createItemWithCluster('/photos/IMG_0099.jpg', ItemRole::Duplicate, $otherCluster);

        $group = $this->createGroup($groupKey, [$canonical, $dup2, $dup1, $edit]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        // -duplicate-001 keeps -duplicate-001 (no swap with -duplicate-002)
        $d1 = $group->getItemByPath('/photos/2024-01-01_12-00-00-000-duplicate-001.heic');
        self::assertNotNull($d1);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $d1->proposedName);
        self::assertFalse($d1->renameRequired);

        $d2 = $group->getItemByPath('/photos/2024-01-01_12-00-00-000-duplicate-002.heic');
        self::assertNotNull($d2);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-002.heic', $d2->proposedName);
        self::assertFalse($d2->renameRequired);
    }

    /**
     * Subgroup numbering is stable regardless of item insertion order: cluster bases
     * are sorted alphabetically before assigning subgroup numbers.
     */
    #[Test]
    public function subgroupNumberingIsStableRegardlessOfInsertionOrder(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;

        // Three non-canonical clusters with different base names (hash-derived)
        $clusterA = 'alpha_cluster';
        $clusterB = 'beta_cluster';
        $clusterC = 'charlie_cluster';

        $canonical = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);

        // Add items from clusters in reverse alphabetical order: charlie, beta, alpha
        $itemC = $this->createItemWithCluster('/photos/IMG_0004.jpg', ItemRole::Duplicate, $clusterC);
        $itemB = $this->createItemWithCluster('/photos/IMG_0003.jpg', ItemRole::Duplicate, $clusterB);
        $itemA = $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $clusterA);

        $group = $this->createGroup($groupKey, [$canonical, $itemC, $itemB, $itemA]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        // Subgroup numbers assigned by sorted cluster base: alpha=002, beta=003, charlie=004
        $resolvedA = $group->getItemByPath('/photos/IMG_0002.jpg');
        self::assertNotNull($resolvedA);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $resolvedA->proposedName);

        $resolvedB = $group->getItemByPath('/photos/IMG_0003.jpg');
        self::assertNotNull($resolvedB);
        self::assertSame('/photos/2024-01-01_12-00-00-000-003.jpg', $resolvedB->proposedName);

        $resolvedC = $group->getItemByPath('/photos/IMG_0004.jpg');
        self::assertNotNull($resolvedC);
        self::assertSame('/photos/2024-01-01_12-00-00-000-004.jpg', $resolvedC->proposedName);
    }

    /**
     * A companion in a subdirectory that is the only group member there would match
     * the isCrossDirNoConflict conditions (alone in dir, different from canonical dir)
     * IF it reached that check. But companions are resolved via resolveCompanionItem()
     * and `continue` BEFORE isCrossDirNoConflict, so they always receive the subgroup
     * suffix from the subgroupMap — never the clean unsuffixed basename.
     */
    #[Test]
    public function testCompanionInSubdirectoryKeepsSubgroupSuffix(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;
        $otherCluster     = $groupKey . '-002';

        // Canonical HEIC in /photos/ — canonical cluster
        $canonical = $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster);

        // Non-canonical Duplicate JPG in /photos/ — creates a second subgroup
        $duplicate = $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $otherCluster);

        // Companion MOV in /photos/subdir/ — same non-canonical cluster, alone in its dir
        // If the companion reached isCrossDirNoConflict it would get the clean basename
        // (different dir from canonical, only file there). But companions skip that check.
        $companion = $this->createItemWithCluster('/photos/subdir/IMG_0002.mov', ItemRole::Companion, $otherCluster);

        $group = $this->createGroup($groupKey, [$canonical, $duplicate, $companion]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Canonical gets clean name
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);

        // Duplicate in non-canonical cluster gets -002 subgroup suffix
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $items[1]->proposedName);

        // Companion in subdirectory MUST get the subgroup suffix, not the clean basename
        $companionResolved = $group->getItemByPath('/photos/subdir/IMG_0002.mov');
        self::assertNotNull($companionResolved);
        self::assertSame(
            '/photos/subdir/2024-01-01_12-00-00-000-002.mov',
            $companionResolved->proposedName,
            'Companion in subdirectory must keep subgroup suffix — it must not enter the cross-directory shortcut',
        );
    }

    /**
     * Cluster renumbering when new clusters appear is deterministic accepted behavior.
     *
     * buildSubgroupMap() sorts cluster bases alphabetically and assigns sequential
     * numbers (canonical=0, others=2,3,...). When MERGE_THRESHOLD changes and
     * SubgroupClassifier produces different clusters, the subgroup map numbers shift.
     * This test documents:
     *   - Run 1 + Run 2: identical inputs produce identical subgroup numbers (determinism).
     *   - Run 3: a new cluster can shift existing subgroup numbers (accepted behavior).
     */
    #[Test]
    public function testClusterRenumberingIsDeterministicWhenNewClusterAppears(): void
    {
        $groupKey         = '2024-01-01_12-00-00-000';
        $canonicalCluster = $groupKey;

        // Use cluster names where alphabetical order is unambiguous
        $clusterB = 'bravo_hash';
        $clusterC = 'charlie_hash';
        // A new cluster that sorts BEFORE bravo (simulates tighter MERGE_THRESHOLD splitting)
        $clusterA = 'alpha_hash';

        // --- Run 1: two clusters (canonical + bravo) ---
        $resolver1 = TargetNameResolverFactory::create();

        $group1 = $this->createGroup($groupKey, [
            $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster),
            $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $clusterB),
        ]);

        $groups1 = new AssetGroupCollection();
        $groups1->set($groupKey, $group1);

        $resolver1->resolve($groups1);

        $run1BravoItem = $group1->getItemByPath('/photos/IMG_0002.jpg');
        self::assertNotNull($run1BravoItem);
        $run1BravoName = $run1BravoItem->proposedName;

        // --- Run 2: identical setup — must produce same result (determinism proof) ---
        $resolver2 = TargetNameResolverFactory::create();

        $group2 = $this->createGroup($groupKey, [
            $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster),
            $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $clusterB),
        ]);

        $groups2 = new AssetGroupCollection();
        $groups2->set($groupKey, $group2);

        $resolver2->resolve($groups2);

        $run2BravoItem = $group2->getItemByPath('/photos/IMG_0002.jpg');
        self::assertNotNull($run2BravoItem);

        self::assertSame(
            $run1BravoName,
            $run2BravoItem->proposedName,
            'Determinism: identical inputs must produce identical subgroup numbers',
        );

        // --- Run 3: add a third cluster that sorts after bravo (charlie) ---
        $resolver3 = TargetNameResolverFactory::create();

        $group3 = $this->createGroup($groupKey, [
            $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster),
            $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $clusterB),
            $this->createItemWithCluster('/photos/IMG_0003.jpg', ItemRole::Duplicate, $clusterC),
        ]);

        $groups3 = new AssetGroupCollection();
        $groups3->set($groupKey, $group3);

        $resolver3->resolve($groups3);

        $run3BravoItem   = $group3->getItemByPath('/photos/IMG_0002.jpg');
        $run3CharlieItem = $group3->getItemByPath('/photos/IMG_0003.jpg');
        self::assertNotNull($run3BravoItem);
        self::assertNotNull($run3CharlieItem);

        // Bravo and charlie must get different subgroup numbers
        self::assertNotSame(
            $run3BravoItem->proposedName,
            $run3CharlieItem->proposedName,
            'Different clusters must receive different subgroup numbers',
        );

        // --- Run 3b: add alpha_hash which sorts BEFORE bravo, demonstrating the shift ---
        // Accepted behavior: subgroup numbers are derived from the current cluster set,
        // not preserved across threshold changes. When alpha_hash is introduced and sorts
        // before bravo_hash, bravo's number shifts from 002 to 003 because alpha_hash
        // now occupies position 002.
        $resolver3b = TargetNameResolverFactory::create();

        $group3b = $this->createGroup($groupKey, [
            $this->createItemWithCluster('/photos/IMG_0001.heic', ItemRole::Canonical, $canonicalCluster),
            $this->createItemWithCluster('/photos/IMG_0002.jpg', ItemRole::Duplicate, $clusterB),
            $this->createItemWithCluster('/photos/IMG_0004.jpg', ItemRole::Duplicate, $clusterA),
        ]);

        $groups3b = new AssetGroupCollection();
        $groups3b->set($groupKey, $group3b);

        $resolver3b->resolve($groups3b);

        $run3bAlphaItem = $group3b->getItemByPath('/photos/IMG_0004.jpg');
        $run3bBravoItem = $group3b->getItemByPath('/photos/IMG_0002.jpg');
        self::assertNotNull($run3bAlphaItem);
        self::assertNotNull($run3bBravoItem);

        // Alpha and bravo must receive different subgroup numbers
        self::assertNotSame(
            $run3bAlphaItem->proposedName,
            $run3bBravoItem->proposedName,
            'Alpha and bravo must receive different subgroup numbers',
        );

        // Verify the shift: bravo's number in Run 3b differs from Run 1/2 because
        // alpha_hash now occupies the earlier position in the sorted order.
        self::assertNotSame(
            $run1BravoName,
            $run3bBravoItem->proposedName,
            'Accepted behavior: bravo subgroup number shifts when a new cluster sorts before it',
        );
    }

    /**
     * Degraded group with existing subgroup names matching groupKey-NNN pattern:
     * all 5 conditions met, items preserve their current filenames as proposedName.
     *
     * Conditions:
     * 1. Group isClassificationDegraded() is true
     * 2. At least one non-Canonical basename matches groupKey-NNN pattern
     * 3. No two items claim the same clean subgroup basename
     * 4. Existing duplicate numbering within subgroups is consistent
     * 5. No item has a clusterId set (truly degraded)
     */
    #[Test]
    public function testDegradedGroupWithExistingSubgroupNamesPreservesNames(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey = '2024-01-01_12-00-00-000';

        // Items already named from a prior successful subgroup run
        $canonical = $this->createItem('/photos/2024-01-01_12-00-00-000.heic', ItemRole::Canonical);
        $subgroup2 = $this->createItem('/photos/2024-01-01_12-00-00-000-002.jpg', ItemRole::Duplicate);
        $subgroup3 = $this->createItem('/photos/2024-01-01_12-00-00-000-003.jpg', ItemRole::Duplicate);

        $group = $this->createDegradedGroup($groupKey, [$canonical, $subgroup2, $subgroup3]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Canonical keeps clean name
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertFalse($items[0]->renameRequired);

        // Subgroup items preserve their existing names
        $sub2 = $group->getItemByPath('/photos/2024-01-01_12-00-00-000-002.jpg');
        self::assertNotNull($sub2);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $sub2->proposedName);
        self::assertFalse($sub2->renameRequired);

        $sub3 = $group->getItemByPath('/photos/2024-01-01_12-00-00-000-003.jpg');
        self::assertNotNull($sub3);
        self::assertSame('/photos/2024-01-01_12-00-00-000-003.jpg', $sub3->proposedName);
        self::assertFalse($sub3->renameRequired);
    }

    /**
     * Degraded group where two items claim the same subgroup basename: condition 3 fails,
     * falls through to flat duplicate naming.
     */
    #[Test]
    public function testDegradedGroupWithConflictingSubgroupNamesFallsThrough(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey = '2024-01-01_12-00-00-000';

        // Two items have the same subgroup number -002 (conflict)
        $canonical = $this->createItem('/photos/2024-01-01_12-00-00-000.heic', ItemRole::Canonical);
        $conflict1 = $this->createItem('/photos/2024-01-01_12-00-00-000-002.jpg', ItemRole::Duplicate);
        $conflict2 = $this->createItem('/photos/backup/2024-01-01_12-00-00-000-002.jpg', ItemRole::Duplicate);

        $group = $this->createDegradedGroup($groupKey, [$canonical, $conflict1, $conflict2]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Falls through to flat naming: canonical clean, duplicates get -duplicate-NNN
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-duplicate-001.heic', $items[1]->proposedName);
        self::assertSame('/photos/backup/2024-01-01_12-00-00-000-duplicate-002.heic', $items[2]->proposedName);
    }

    /**
     * Degraded group where some items have clusterIds: condition 5 fails,
     * falls through to normal naming (not truly degraded, partial classification).
     */
    #[Test]
    public function testDegradedGroupWithPartialClusterIdsFallsThrough(): void
    {
        $resolver = TargetNameResolverFactory::create();

        $groupKey = '2024-01-01_12-00-00-000';

        // Item with clusterId means condition 5 (no clusterIds) fails
        $canonical = $this->createItemWithCluster('/photos/2024-01-01_12-00-00-000.heic', ItemRole::Canonical, $groupKey);
        $subgroup  = $this->createItem('/photos/2024-01-01_12-00-00-000-002.jpg', ItemRole::Duplicate);

        $group = $this->createDegradedGroup($groupKey, [$canonical, $subgroup]);

        $groups = new AssetGroupCollection();
        $groups->set($groupKey, $group);

        $resolver->resolve($groups);

        $items = $group->getItems();

        // Falls through: partial classification triggers subgroup path (hasMultipleSubgroups
        // returns true because of mix of classified + unclassified). But the key point is
        // the degraded recovery did NOT intercept — the subgroup path handles it.
        // The canonical gets clean name, the unclassified item gets -002 (implicit subgroup).
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $items[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $items[1]->proposedName);
    }

    private function createItem(string $pathname, ItemRole $role): AssetItem
    {
        return new AssetItem(
            new SplFileInfo($pathname),
            role: $role,
        );
    }

    private function createItemWithCluster(string $pathname, ItemRole $role, string $clusterId): AssetItem
    {
        return new AssetItem(
            new SplFileInfo($pathname),
            role: $role,
            clusterId: $clusterId,
        );
    }

    private function createItemWithClusterAndRank(string $pathname, ItemRole $role, string $clusterId, int $clusterRank): AssetItem
    {
        return new AssetItem(
            new SplFileInfo($pathname),
            role: $role,
            clusterId: $clusterId,
            clusterRank: $clusterRank,
        );
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

    /**
     * Creates a group marked as classification-degraded (Hash-Fehler).
     *
     * @param list<AssetItem> $items
     */
    private function createDegradedGroup(string $groupKey, array $items): AssetGroup
    {
        $group = new AssetGroup($groupKey);
        $group->markClassificationFailed('Hash-Fehler');

        foreach ($items as $item) {
            $group->addItem($item);
        }

        return $group;
    }
}
