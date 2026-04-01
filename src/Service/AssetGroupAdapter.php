<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use SplFileInfo;

use function array_merge;

/**
 * Transitional bridge converting AssetGroupCollection to FileDuplicateCollection
 * for the existing FileSystemService execution phase.
 *
 * Strictly transitional — no domain logic beyond mapping. Will be removed once
 * FileSystemService is refactored to consume AssetGroupCollection directly.
 *
 * @deprecated Retained only for differential tests during migration.
 *             Production execution uses ExecutionPlanBuilder + FileSystemService::executePlan().
 *             Will be removed once differential tests are no longer needed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class AssetGroupAdapter
{
    /**
     * Converts an AssetGroupCollection to a FileDuplicateCollection
     * for the existing FileSystemService execution phase.
     *
     * For each group:
     * 1. Creates a FileDuplicate
     * 2. Sets the target from the canonical's proposedName (if available)
     * 3. Orders items: Canonical -> Companions -> Duplicates -> Ambiguous
     * 4. For each item: addFile() + addRename() if proposedName is set
     * 5. Prefixes the key with LIVE_PHOTO_IDENTIFIER_PREFIX when the group has companions
     *
     * @param AssetGroupCollection $groups Source asset groups to convert
     *
     * @return FileDuplicateCollection Mapped collection for FileSystemService
     */
    public function toFileDuplicateCollection(AssetGroupCollection $groups): FileDuplicateCollection
    {
        $collection = new FileDuplicateCollection();

        foreach ($groups as $key => $group) {
            $fileDuplicate = $this->convertGroup($group);
            $collectionKey = $this->resolveKey($key, $group);

            $collection->set($collectionKey, $fileDuplicate);
        }

        return $collection;
    }

    /**
     * Converts a single AssetGroup to a FileDuplicate instance.
     *
     * @param AssetGroup $group The asset group to convert
     *
     * @return FileDuplicate The resulting file duplicate group
     */
    private function convertGroup(AssetGroup $group): FileDuplicate
    {
        $fileDuplicate = new FileDuplicate();

        $canonical = $group->getCanonical();

        if (($canonical instanceof AssetItem) && ($canonical->proposedName !== null)) {
            $fileDuplicate->setTarget(new SplFileInfo($canonical->proposedName));
        } elseif ($canonical instanceof AssetItem) {
            // Fallback: use canonical's current file path if no proposed name was set
            // (e.g. TargetNameResolver was skipped or group is empty)
            $fileDuplicate->setTarget($canonical->file);
        }

        $orderedItems = $this->orderItems($group);

        foreach ($orderedItems as $item) {
            $fileDuplicate->addFile($item->file);

            if ($item->proposedName !== null) {
                $fileDuplicate->addRename(
                    new Rename(
                        $item->file,
                        new SplFileInfo($item->proposedName),
                    ),
                );
            }
        }

        return $fileDuplicate;
    }

    /**
     * Orders items by role: Canonical -> Companions -> Duplicates -> Ambiguous.
     * FileSystemService and RenameOutputRenderer expect the canonical's Rename first.
     *
     * @param AssetGroup $group The group whose items to order
     *
     * @return list<AssetItem> Items sorted by role priority
     */
    private function orderItems(AssetGroup $group): array
    {
        $canonical  = $group->getCanonical();
        $companions = $group->getCompanions();
        $duplicates = $group->getDuplicates();
        $ambiguous  = $group->getAmbiguous();

        $ordered = [];

        if ($canonical instanceof AssetItem) {
            $ordered[] = $canonical;
        }

        return array_merge($ordered, $companions, $duplicates, $ambiguous);
    }

    /**
     * Resolves the collection key, prefixing with the Live Photo identifier
     * when the group contains Companion-role items.
     *
     * @param string     $key   Original group key
     * @param AssetGroup $group The group to inspect for companions
     *
     * @return string The resolved collection key
     */
    private function resolveKey(string $key, AssetGroup $group): string
    {
        if ($group->getCompanions() !== []) {
            return Constants::LIVE_PHOTO_IDENTIFIER_PREFIX . $key;
        }

        return $key;
    }
}
