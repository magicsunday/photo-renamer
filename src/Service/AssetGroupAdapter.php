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
 * Retained only for differential tests during migration. Production execution
 * uses ExecutionPlanBuilder + FileSystemService::executePlan(). This adapter can
 * be removed once differential tests are no longer needed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class AssetGroupAdapter
{
    /**
     * Converts an AssetGroupCollection to a FileDuplicateCollection for the existing
     * FileSystemService execution phase.
     *
     * This method acts as a mapping engine between the new domain model (AssetGroup)
     * and the legacy execution model (FileDuplicate). It ensures that groups
     * identified as Live Photos receive a special prefix in their collection key,
     * which is essential for consistent UI rendering in the console output.
     *
     * @param AssetGroupCollection $groups The source asset groups containing the classified
     *                                     and ranked files to be converted.
     *
     * @return FileDuplicateCollection A mapped collection compatible with the legacy
     *                                 FileSystemService.
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
     * Converts a single AssetGroup to a FileDuplicate instance, establishing
     * the primary target and populating the internal file and rename lists.
     *
     * The method prioritizes the canonical item's proposed name as the global
     * target path for the whole group. If no proposed name exists, it falls back
     * to the current physical file path of the canonical item.
     *
     * @param AssetGroup $group The specific asset group to be transformed into
     *                          the legacy model.
     *
     * @return FileDuplicate A populated FileDuplicate object representing the group's
     *                       planned file operations.
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
     *
     * This specific order is critical because both FileSystemService and
     * RenameOutputRenderer expect the canonical item's Rename entry to be the
     * first in the list for proper identification and reporting of the primary
     * file operation.
     *
     * @param AssetGroup $group The group whose items should be sorted according
     *                          to their role priority.
     *
     * @return list<AssetItem> A sorted list of AssetItems, starting with the
     *                         canonical item.
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
     * Resolves the collection key, applying the Live Photo prefix if necessary.
     *
     * This ensures that Live Photo groups are easily identifiable in later
     * processing stages (like rendering) by inspecting the key's prefix.
     *
     * @param string     $key   The original group key (usually a timestamp).
     * @param AssetGroup $group The group to check for the presence of companions
     *                          (which triggers the Live Photo prefix).
     *
     * @return string The final collection key, possibly prefixed.
     */
    private function resolveKey(string $key, AssetGroup $group): string
    {
        if ($group->getCompanions() !== []) {
            return Constants::LIVE_PHOTO_IDENTIFIER_PREFIX . $key;
        }

        return $key;
    }
}
