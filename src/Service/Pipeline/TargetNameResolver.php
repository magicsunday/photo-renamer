<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\ItemRole;
use Override;

use function array_unique;
use function count;

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
     */
    public function __construct(
        private FlatGroupNameResolver $flatGroupNameResolver = new FlatGroupNameResolver(),
        private SubgroupNameResolver $subgroupNameResolver = new SubgroupNameResolver(),
        private ExistingSubgroupNamePreserver $existingSubgroupNamePreserver = new ExistingSubgroupNamePreserver(),
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

        if ($this->hasMultipleSubgroups($items)) {
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
     * Returns true when non-Companion items have more than one distinct cluster base,
     * or when some items have a clusterId while others do not (partial classification).
     *
     * Normalizes clusterIds by stripping -duplicate-NNN suffixes before comparing,
     * because SubgroupClassifier assigns unique clusterIds per file (including the
     * duplicate suffix), while subgroup identity is determined by the prefix only.
     *
     * If ANY item has a non-null clusterId AND any other item has a null clusterId,
     * the null-clusterId items form an implicit separate cluster. This ensures partial
     * SubgroupClassifier failures don't silently downgrade to flat naming.
     *
     * @param list<AssetItem> $items All items in the group
     */
    private function hasMultipleSubgroups(array $items): bool
    {
        /** @var list<string> $clusterBases */
        $clusterBases      = [];
        $hasNullCluster    = false;
        $hasNonNullCluster = false;

        foreach ($items as $item) {
            if ($item->role === ItemRole::Companion) {
                continue;
            }

            if ($item->clusterId !== null) {
                $clusterBases[]    = $this->normalizeClusterId($item->clusterId);
                $hasNonNullCluster = true;
            } else {
                $hasNullCluster = true;
            }
        }

        // Multiple explicit clusters
        if (count(array_unique($clusterBases)) > 1) {
            return true;
        }

        // Mix of classified and unclassified items = implicit second cluster
        return $hasNonNullCluster && $hasNullCluster;
    }

    /**
     * Strips -duplicate-NNN suffixes from a clusterId to get the stable cluster base.
     *
     * hasMultipleSubgroups() must compare normalized cluster identity, not the
     * per-file duplicate suffixes attached by SubgroupClassifier.
     *
     * @param string $clusterId Raw clusterId from SubgroupClassifier
     *
     * @return string Normalized cluster base without -duplicate-NNN suffixes
     */
    private function normalizeClusterId(string $clusterId): string
    {
        return (string) preg_replace(
            '/' . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '\d+$/',
            '',
            $clusterId,
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
