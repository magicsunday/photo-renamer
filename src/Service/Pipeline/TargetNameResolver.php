<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\ItemRole;
use Override;

/**
 * Pure semantic naming: computes proposed target names from role + group key.
 * No collision checks — that responsibility belongs to CollisionResolver.
 *
 * Canonical items get the clean group key basename. Companions keep their own
 * file extension. Duplicates and ambiguous items receive sequential suffixes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TargetNameResolver implements TargetNameResolverInterface
{
    /**
     * @param FlatGroupNameResolver         $flatGroupNameResolver         Resolves the simple naming path when a group does not split into subgroups.
     * @param SubgroupNameResolver          $subgroupNameResolver          Resolves naming once a group contains multiple content subgroups.
     * @param ExistingSubgroupNamePreserver $existingSubgroupNamePreserver Preserves coherent subgroup names when degraded classification proves a prior successful run should remain authoritative.
     * @param SubgroupPresenceDetector      $subgroupPresenceDetector      Detects whether items represent multiple effective subgroups or just one flat cluster.
     */
    public function __construct(
        private FlatGroupNameResolver $flatGroupNameResolver = new FlatGroupNameResolver(),
        private SubgroupNameResolver $subgroupNameResolver = new SubgroupNameResolver(),
        private ExistingSubgroupNamePreserver $existingSubgroupNamePreserver = new ExistingSubgroupNamePreserver(),
        private SubgroupPresenceDetector $subgroupPresenceDetector = new SubgroupPresenceDetector(),
    ) {
    }

    /**
     * Computes desired target names for all items in all groups based on their role
     * and group key.
     *
     * @param AssetGroupCollection $groups                     Groups with role-assigned items
     * @param bool                 $useFileExtensionFromSource When true, preserve source extension in target
     */
    #[Override]
    public function resolve(
        AssetGroupCollection $groups,
        bool $useFileExtensionFromSource = false,
    ): void {
        foreach ($groups as $group) {
            $this->resolveGroup($group, $useFileExtensionFromSource);
        }
    }

    /**
     * Computes proposed names for all items within a single group.
     *
     * Detects whether multiple distinct clusterIds exist among non-Companion items.
     * When they do, delegates to subgroup-aware naming where each non-canonical cluster
     * receives a sequential suffix (-002, -003, ...) and duplicates within each cluster
     * receive additional -duplicate-NNN suffixes.
     *
     * When classification is degraded (isClassificationDegraded()), all items have null
     * clusterIds, so hasMultipleSubgroups() returns false and the group naturally falls
     * through to the simple flat-naming path with sequential duplicate suffixes. This is
     * the safe conservative behavior: no subgroup suffixes are assigned when classification
     * data is unreliable.
     *
     * @param AssetGroup $group                      Group to resolve
     * @param bool       $useFileExtensionFromSource Whether to preserve source extension
     */
    private function resolveGroup(AssetGroup $group, bool $useFileExtensionFromSource): void
    {
        $items = $group->getItems();

        if ($items === []) {
            return;
        }

        $canonical          = $group->getCanonical();
        $canonicalExtension = ($canonical instanceof AssetItem)
            ? FileHelper::normalizeExtension($canonical->file->getExtension())
            : '';

        // Degraded classification recovery: when hash comparison failed but existing
        // filenames demonstrate a prior successful subgroup run, preserve those names
        // instead of flattening to -duplicate-NNN. This is a DELIBERATE, narrowly
        // bounded exception to the rule that current filenames must not drive pipeline
        // decisions. The 5 strict conditions in tryPreserveExistingSubgroupNames()
        // limit this to cases where names demonstrably come from a prior successful run.
        if (
            $group->isClassificationDegraded()
            && $this->existingSubgroupNamePreserver->preserveIfPossible(
                $group,
                $items,
                $canonicalExtension,
                $useFileExtensionFromSource,
                $this->resolveExtension(...),
            )
        ) {
            return;
        }

        if ($this->subgroupPresenceDetector->hasMultipleSubgroups($items)) {
            $this->subgroupNameResolver->resolve(
                $group,
                $canonical,
                $canonicalExtension,
                $useFileExtensionFromSource,
                $this->resolveExtension(...),
            );

            return;
        }

        $this->flatGroupNameResolver->resolve(
            $group,
            $items,
            $canonicalExtension,
            $useFileExtensionFromSource,
            $this->resolveExtension(...),
        );
    }

    /**
     * Determines the target file extension for an item based on its role and settings.
     *
     * @param AssetItem $item                       Item whose extension is being resolved
     * @param string    $canonicalExtension         Normalized extension of the canonical item
     * @param bool      $useFileExtensionFromSource Whether to preserve source extension
     */
    private function resolveExtension(
        AssetItem $item,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
    ): string {
        // Companions always keep their own extension
        if ($item->role === ItemRole::Companion) {
            return FileHelper::normalizeExtension($item->file->getExtension());
        }

        if ($useFileExtensionFromSource) {
            return FileHelper::normalizeExtension($item->file->getExtension());
        }

        return $canonicalExtension;
    }
}
