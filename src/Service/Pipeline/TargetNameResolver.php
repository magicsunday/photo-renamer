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
use function sprintf;

use const DIRECTORY_SEPARATOR;

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
     * @param SubgroupNameResolver          $subgroupNameResolver          Resolves naming once a group contains multiple content subgroups.
     * @param ExistingSubgroupNamePreserver $existingSubgroupNamePreserver Preserves coherent subgroup names when degraded classification proves a prior successful run should remain authoritative.
     */
    public function __construct(
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

        /** @var array<string, int> $sequenceCounterByExt */
        $sequenceCounterByExt = [];

        foreach ($items as $item) {
            $extension = $this->resolveExtension(
                $item,
                $canonicalExtension,
                $useFileExtensionFromSource,
            );

            $sequenceCounterByExt[$extension] ??= 0;

            $directory    = $item->file->getPath();
            $proposedName = $this->buildProposedName(
                $directory,
                $group->groupKey,
                $extension,
                $item->role,
                $sequenceCounterByExt[$extension],
            );

            $updated = $item->withProposedName($proposedName);

            if (($item->role === ItemRole::Duplicate) || ($item->role === ItemRole::Ambiguous)) {
                $updated = $updated->withSequenceNumber($sequenceCounterByExt[$extension]);
            }

            $group->replaceItem($item, $updated);
        }
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

    /**
     * Builds the full proposed pathname from directory, group key, extension, and role.
     * Duplicates and ambiguous items receive an auto-incremented sequence suffix.
     *
     * @param string   $directory       Directory part of the target path
     * @param string   $groupKey        Stable group key used as the basename
     * @param string   $extension       Normalized file extension (without leading dot)
     * @param ItemRole $role            Item role determining suffix behavior
     * @param int      $sequenceCounter Running counter for duplicate/ambiguous suffixes (modified by reference)
     */
    private function buildProposedName(
        string $directory,
        string $groupKey,
        string $extension,
        ItemRole $role,
        int &$sequenceCounter,
    ): string {
        return match ($role) {
            ItemRole::Canonical,
            ItemRole::Companion => $this->buildCleanName($directory, $groupKey, $extension),
            ItemRole::Duplicate,
            ItemRole::Ambiguous => $this->buildDuplicateName($directory, $groupKey, ++$sequenceCounter, $extension),
        };
    }

    /**
     * Builds a clean target pathname without any duplicate or subgroup suffix.
     *
     * @param string $directory Directory part of the target path
     * @param string $basename  Base filename (without extension)
     * @param string $extension Normalized file extension (without leading dot)
     */
    private function buildCleanName(string $directory, string $basename, string $extension): string
    {
        return $directory . DIRECTORY_SEPARATOR . $basename . '.' . $extension;
    }

    /**
     * Builds a target pathname with a duplicate suffix (e.g. "key-duplicate-001").
     *
     * @param string $directory       Directory part of the target path
     * @param string $basename        Base filename (without extension)
     * @param int    $duplicateNumber Sequential duplicate number (e.g. 1 → "-duplicate-001")
     * @param string $extension       Normalized file extension (without leading dot)
     */
    private function buildDuplicateName(string $directory, string $basename, int $duplicateNumber, string $extension): string
    {
        return $directory . DIRECTORY_SEPARATOR . sprintf('%s%s%03d', $basename, Constants::DUPLICATE_IDENTIFIER, $duplicateNumber) . '.' . $extension;
    }
}
