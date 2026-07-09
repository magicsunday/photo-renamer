<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\AssetGroupAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the AssetGroupAdapter correctly maps AssetGroupCollection
 * to FileDuplicateCollection for the existing FileSystemService execution phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(AssetGroupAdapter::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(FileDuplicateCollection::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(Rename::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(Constants::class)]
final class AssetGroupAdapterTest extends TestCase
{
    /**
     * Verifies that a canonical HEIC file and its duplicate JPG are correctly
     * converted. The resulting {@see FileDuplicate} must use the canonical's
     * proposed name as the target path and contain both files in its rename list.
     * This ensures that duplicates are correctly associated with their originals
     * even across different formats.
     */
    #[Test]
    public function convertsCanonicalWithDuplicates(): void
    {
        $canonical = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            ItemRole::Canonical,
            proposedName: '/photos/2024-08-31_14-22-08.heic',
        );

        $duplicate = new AssetItem(
            new SplFileInfo('/photos/IMG_0002.jpg'),
            ItemRole::Duplicate,
            proposedName: '/photos/2024-08-31_14-22-08-duplicate-001.jpg',
        );

        $group = new AssetGroup('2024-08-31_14-22-08');
        $group->addItem($canonical);
        $group->addItem($duplicate);

        $groups = new AssetGroupCollection();
        $groups->set('2024-08-31_14-22-08', $group);

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        self::assertCount(1, $result);

        $fileDuplicate = $result->get('2024-08-31_14-22-08');

        self::assertNotNull($fileDuplicate);
        self::assertSame('/photos/2024-08-31_14-22-08.heic', $fileDuplicate->getTarget()->getPathname());
        self::assertCount(2, $fileDuplicate->getFiles());
        self::assertCount(2, $fileDuplicate->getRenames());
    }

    /**
     * Verifies that Live Photo pairs (HEIC + MOV) are both included in the
     * rename operations. Both files must receive rename entries derived from
     * their respective proposed names to ensure they stay together and
     * share the same base name in the target directory.
     */
    #[Test]
    public function convertsGroupWithCompanion(): void
    {
        $canonical = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            ItemRole::Canonical,
            proposedName: '/photos/2024-08-31_14-22-08.heic',
        );

        $companion = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            ItemRole::Companion,
            proposedName: '/photos/2024-08-31_14-22-08.mov',
        );

        $group = new AssetGroup('2024-08-31_14-22-08');
        $group->addItem($canonical);
        $group->addItem($companion);

        $groups = new AssetGroupCollection();
        $groups->set('2024-08-31_14-22-08', $group);

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        $key           = Constants::LIVE_PHOTO_IDENTIFIER_PREFIX . '2024-08-31_14-22-08';
        $fileDuplicate = $result->get($key);

        self::assertNotNull($fileDuplicate);
        self::assertCount(2, $fileDuplicate->getRenames());

        $renames = $fileDuplicate->getRenames()->asArray();

        self::assertSame('/photos/IMG_0001.heic', $renames[0]->getSource()->getPathname());
        self::assertSame('/photos/2024-08-31_14-22-08.heic', $renames[0]->getTarget()->getPathname());

        self::assertSame('/photos/IMG_0001.mov', $renames[1]->getSource()->getPathname());
        self::assertSame('/photos/2024-08-31_14-22-08.mov', $renames[1]->getTarget()->getPathname());
    }

    /**
     * Verifies that groups containing at least one {@see ItemRole::Companion}
     * (Live Photo video) receive a special prefix in their collection key.
     * This prefix is required by the {@see RenameOutputRenderer} to correctly
     * identify and format Live Photo groups in the console output.
     */
    #[Test]
    public function livePhotoGroupGetsPrefixedKey(): void
    {
        $canonical = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            ItemRole::Canonical,
            proposedName: '/photos/2024-08-31_14-22-08.heic',
        );

        $companion = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            ItemRole::Companion,
            proposedName: '/photos/2024-08-31_14-22-08.mov',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($canonical);
        $group->addItem($companion);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        $expectedKey = Constants::LIVE_PHOTO_IDENTIFIER_PREFIX . 'group-1';

        self::assertTrue($result->has($expectedKey));
        self::assertFalse($result->has('group-1'));
    }

    /**
     * Verifies that standard asset groups without companions keep their
     * original group key (e.g., the timestamp). No prefix should be added
     * if the group consists only of a canonical item and its duplicates.
     */
    #[Test]
    public function nonLivePhotoGroupKeepsPlainKey(): void
    {
        $canonical = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            ItemRole::Canonical,
            proposedName: '/photos/2024-08-31_14-22-08.heic',
        );

        $duplicate = new AssetItem(
            new SplFileInfo('/photos/IMG_0002.jpg'),
            ItemRole::Duplicate,
            proposedName: '/photos/2024-08-31_14-22-08-duplicate-001.jpg',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($canonical);
        $group->addItem($duplicate);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        self::assertTrue($result->has('group-1'));
        self::assertFalse($result->has(Constants::LIVE_PHOTO_IDENTIFIER_PREFIX . 'group-1'));
    }

    /**
     * Verifies that the canonical item is always placed first in the
     * {@see FileDuplicate} list, even if it was added to the {@see AssetGroup}
     * after its duplicates. This ordering is critical for the legacy
     * {@see FileSystemService} to correctly identify the primary file of a group.
     */
    #[Test]
    public function canonicalRenameIsFirst(): void
    {
        // Add duplicate BEFORE canonical to verify ordering
        $duplicate = new AssetItem(
            new SplFileInfo('/photos/IMG_0002.jpg'),
            ItemRole::Duplicate,
            proposedName: '/photos/2024-08-31_14-22-08-duplicate-001.jpg',
        );

        $canonical = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            ItemRole::Canonical,
            proposedName: '/photos/2024-08-31_14-22-08.heic',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($duplicate);
        $group->addItem($canonical);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        $fileDuplicate = $result->get('group-1');

        self::assertNotNull($fileDuplicate);

        $renames = $fileDuplicate->getRenames()->asArray();

        self::assertCount(2, $renames);
        self::assertSame('/photos/IMG_0001.heic', $renames[0]->getSource()->getPathname());
    }

    /**
     * Verifies that an item without a proposed name is still included in the
     * group's file list but does not generate a rename operation.
     *
     * This occurs when an item is identified as a duplicate but no rename is
     * planned for it (e.g., in a specific dry-run or when the rename strategy
     * skips certain items).
     */
    #[Test]
    public function itemsWithoutProposedNameSkipRename(): void
    {
        $canonical = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            ItemRole::Canonical,
            proposedName: '/photos/2024-08-31_14-22-08.heic',
        );

        $ambiguous = new AssetItem(
            new SplFileInfo('/photos/IMG_0003.jpg'),
            ItemRole::Ambiguous,
        );

        $group = new AssetGroup('group-1');
        $group->addItem($canonical);
        $group->addItem($ambiguous);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        $fileDuplicate = $result->get('group-1');

        self::assertNotNull($fileDuplicate);
        self::assertCount(2, $fileDuplicate->getFiles());
        self::assertCount(1, $fileDuplicate->getRenames());
    }

    /**
     * Verifies that when a canonical item lacks a proposed name, the resulting
     * FileDuplicate's target path defaults to the item's current physical path.
     *
     * This ensures the legacy execution layer always has a valid target object
     * to work with, even if the renaming strategy was skipped or didn't
     * produce a new name.
     */
    #[Test]
    public function canonicalWithoutProposedNameUsesCurrentPath(): void
    {
        $canonical = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            ItemRole::Canonical,
        );

        $group = new AssetGroup('group-1');
        $group->addItem($canonical);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        $fileDuplicate = $result->get('group-1');

        self::assertNotNull($fileDuplicate);
        self::assertSame('/photos/IMG_0001.heic', $fileDuplicate->getTarget()->getPathname());
    }

    /**
     * Verifies that an empty collection of asset groups results in an empty
     * collection of file duplicates.
     *
     * This confirms the adapter handles the edge case of no input files
     * gracefully without errors.
     */
    #[Test]
    public function emptyCollectionReturnsEmptyCollection(): void
    {
        $groups = new AssetGroupCollection();

        $adapter = new AssetGroupAdapter();
        $result  = $adapter->toFileDuplicateCollection($groups);

        self::assertCount(0, $result);
    }
}
