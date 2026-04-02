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
use SplFileInfo;

use function array_keys;
use function array_unique;
use function count;
use function max;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function sort;
use function sprintf;
use function usort;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;

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
            && $this->tryPreserveExistingSubgroupNames($group, $items, $canonicalExtension, $useFileExtensionFromSource)
        ) {
            return;
        }

        if ($this->hasMultipleSubgroups($items)) {
            $this->resolveWithSubgroups($group, $canonical, $canonicalExtension, $useFileExtensionFromSource);

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
     * Attempts to preserve existing subgroup names from a prior successful run when
     * classification is degraded (Hash-Fehler). Returns true when recovery was applied,
     * false when any of the 5 strict conditions failed and normal naming should proceed.
     *
     * This is a DELIBERATE, narrowly bounded degraded-mode exception to the rule that
     * current filenames must not drive pipeline decisions. The strict conditions ensure
     * this only activates when names demonstrably come from a prior successful subgroup run.
     *
     * Conditions (ALL must be true):
     * 1. Group isClassificationDegraded() — checked by caller
     * 2. At least one non-Canonical item basename matches groupKey-NNN pattern
     * 3. No two items claim the same clean subgroup basename (no conflicts)
     * 4. Existing duplicate numbering within subgroups is consistent (no gaps, no duplicates)
     * 5. No item has a clusterId set (truly degraded, not partial)
     *
     * @param AssetGroup      $group                      The degraded group to recover
     * @param list<AssetItem> $items                      All items in the group
     * @param string          $canonicalExtension         Normalized extension of the canonical
     * @param bool            $useFileExtensionFromSource Whether to preserve source extension
     *
     * @return bool True when recovery was applied, false when conditions failed
     */
    private function tryPreserveExistingSubgroupNames(
        AssetGroup $group,
        array $items,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
    ): bool {
        $groupKey = $group->groupKey;

        // Condition 5: No item has a clusterId set (truly degraded)
        foreach ($items as $item) {
            if ($item->clusterId !== null) {
                return false;
            }
        }

        // Condition 2: At least one non-Canonical basename matches groupKey-NNN
        if (!$this->hasExistingSubgroupPattern($items, $groupKey)) {
            return false;
        }

        // Parse existing subgroup basenames from all non-Companion items.
        // For each item, determine its "clean subgroup basename" — either the groupKey
        // (for canonical-pattern items) or groupKey-NNN (for subgroup-pattern items).
        // Items not matching either pattern are "unrecognized" and get flat naming.
        $subgroupPattern  = '/^' . preg_quote($groupKey, '/') . '-(\d{3})$/';
        $duplicatePattern = '/^' . preg_quote($groupKey, '/') . '-(\d{3})'
            . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '(\d+)$/';

        /** @var array<string, list<AssetItem>> $subgroupBuckets basename => items claiming that basename */
        $subgroupBuckets = [];

        /** @var array<string, list<int>> $subgroupDupNumbers basename => list of duplicate numbers */
        $subgroupDupNumbers = [];

        $hasUnrecognized = false;

        foreach ($items as $item) {
            if ($item->role === ItemRole::Companion) {
                continue;
            }

            $basename = FileHelper::basenameWithoutExtension($item->file);

            // Canonical item (clean groupKey basename)
            if ($basename === $groupKey) {
                $subgroupBuckets[$groupKey][] = $item;

                continue;
            }

            // Subgroup-duplicate pattern: groupKey-NNN-duplicate-MMM
            if (preg_match($duplicatePattern, $basename, $matches) === 1) {
                $cleanBasename                        = $groupKey . '-' . $matches[1];
                $subgroupBuckets[$cleanBasename][]    = $item;
                $subgroupDupNumbers[$cleanBasename][] = (int) $matches[2];

                continue;
            }

            // Clean subgroup pattern: groupKey-NNN
            if (preg_match($subgroupPattern, $basename) === 1) {
                $subgroupBuckets[$basename][] = $item;

                continue;
            }

            // Unrecognized basename — cannot recover this item
            $hasUnrecognized = true;
        }

        // If there are unrecognized items mixed with subgroup items, we cannot
        // reliably recover — fall through to flat naming
        if ($hasUnrecognized) {
            return false;
        }

        // Condition 3: No two items claim the same clean subgroup basename
        // (accounting for duplicates within a subgroup: groupKey-002 and
        // groupKey-002-duplicate-001 are in the same bucket, which is fine)
        foreach ($subgroupBuckets as $cleanBasename => $bucketItems) {
            if ($cleanBasename === $groupKey) {
                // Canonical bucket: the canonical itself + any groupKey-duplicate-NNN items
                continue;
            }

            // Count items that have the EXACT clean basename (not duplicate-suffixed)
            $cleanCount = 0;

            foreach ($bucketItems as $bucketItem) {
                if (FileHelper::basenameWithoutExtension($bucketItem->file) === $cleanBasename) {
                    ++$cleanCount;
                }
            }

            if ($cleanCount > 1) {
                return false;
            }
        }

        // Condition 4: Duplicate numbering within subgroups is consistent
        // (sequential starting from 1, no gaps, no duplicates)
        foreach ($subgroupDupNumbers as $numbers) {
            $sorted = $numbers;
            sort($sorted);

            if (count($sorted) !== count(array_unique($sorted))) {
                return false; // Duplicate numbers
            }

            // Check sequential from 1
            foreach ($sorted as $index => $number) {
                if ($number !== $index + 1) {
                    return false; // Gap in numbering
                }
            }
        }

        // All conditions passed — apply recovery: each item keeps its current pathname
        // as proposedName. Non-canonical subgroup items keep their own extension (same
        // as resolveWithSubgroups()), canonical cluster items use canonical extension.
        $subgroupCleanPattern = '/^' . preg_quote($groupKey, '/') . '-\d{3}/';

        foreach ($items as $item) {
            $basename = FileHelper::basenameWithoutExtension($item->file);

            // Non-canonical subgroup items (basename starts with groupKey-NNN) keep their
            // own extension because they represent content-distinct files (edits, conversions).
            $isSubgroupItem = preg_match($subgroupCleanPattern, $basename) === 1;

            $extension = $isSubgroupItem
                ? FileHelper::normalizeExtension($item->file->getExtension())
                : $this->resolveExtension($item, $canonicalExtension, $useFileExtensionFromSource);

            // Every item keeps its current pathname as the proposed name
            $directory    = $item->file->getPath();
            $proposedName = $directory . DIRECTORY_SEPARATOR . $basename . '.' . $extension;

            $updated = $item->withProposedName($proposedName);

            $group->replaceItem($item, $updated);
        }

        return true;
    }

    /**
     * Returns true when at least one non-Canonical, non-Companion item has a basename
     * matching the subgroup pattern: groupKey-NNN (3 digits).
     *
     * @param list<AssetItem> $items    All items in the group
     * @param string          $groupKey The group's stable key
     */
    private function hasExistingSubgroupPattern(array $items, string $groupKey): bool
    {
        $pattern = '/^' . preg_quote($groupKey, '/') . '-\d{3}('
            . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '\d+)?$/';

        foreach ($items as $item) {
            if ($item->role === ItemRole::Canonical) {
                continue;
            }

            if ($item->role === ItemRole::Companion) {
                continue;
            }

            if (preg_match($pattern, FileHelper::basenameWithoutExtension($item->file)) === 1) {
                return true;
            }
        }

        return false;
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
     * Resolves names using subgroup-aware logic when multiple clusterIds exist.
     *
     * Items in the canonical cluster get clean basenames (canonical) or -duplicate-NNN
     * suffixes. Items in other clusters get -NNN subgroup suffixes, with additional
     * -duplicate-NNN suffixes for second and subsequent items within the same cluster.
     *
     * @param AssetGroup     $group                      Group to resolve
     * @param AssetItem|null $canonical                  The canonical item (if any)
     * @param string         $canonicalExtension         Normalized extension of the canonical
     * @param bool           $useFileExtensionFromSource Whether to preserve source extension
     */
    private function resolveWithSubgroups(
        AssetGroup $group,
        ?AssetItem $canonical,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
    ): void {
        $items    = $group->getItems();
        $groupKey = $group->groupKey;

        // Determine canonical cluster base (normalized, without -duplicate-NNN suffix)
        $canonicalClusterBase = ($canonical instanceof AssetItem && $canonical->clusterId !== null)
            ? $this->normalizeClusterId($canonical->clusterId)
            : null;

        // Assign subgroup numbers: canonical cluster = no suffix, others get 002, 003, ...
        $subgroupMap = $this->buildSubgroupMap($items, $canonicalClusterBase);

        // Determine implicit subgroup number for items with null clusterId (partial
        // classification). They get the next available number after all explicit subgroups.
        $unclassifiedSubgroup = $subgroupMap !== []
            ? max($subgroupMap) + 1
            : 2;

        // Ensure unclassified subgroup starts at 2 minimum (0 = canonical cluster)
        if ($unclassifiedSubgroup < 2) {
            $unclassifiedSubgroup = 2;
        }

        // Idempotency sort: within each cluster, stable 3-priority ordering ensures the
        // same item always receives the clean name regardless of alphabetical filename
        // order from CaptureGroupBuilder.
        //
        // Priority 1: Idempotent match — source basename equals the expected clean name.
        // Priority 2: Existing duplicate number — items with -duplicate-NNN suffix, sorted by NNN.
        // Priority 3: clusterRank — the stable rank assigned by SubgroupClassifier.
        usort($items, function (AssetItem $a, AssetItem $b) use ($subgroupMap, $groupKey, $unclassifiedSubgroup): int {
            // Only reorder within the same cluster
            if ($a->role === ItemRole::Companion || $b->role === ItemRole::Companion) {
                return 0;
            }

            if ($a->role === ItemRole::Canonical || $b->role === ItemRole::Canonical) {
                if ($a->role === ItemRole::Canonical) {
                    return -1;
                }

                return 1;
            }

            $aCluster = ($a->clusterId !== null) ? $this->normalizeClusterId($a->clusterId) : null;
            $bCluster = ($b->clusterId !== null) ? $this->normalizeClusterId($b->clusterId) : null;

            // Different clusters: preserve order
            if ($aCluster !== $bCluster) {
                return 0;
            }

            $subgroup = ($aCluster !== null)
                ? ($subgroupMap[$aCluster] ?? 0)
                : $unclassifiedSubgroup;

            // Canonical cluster: sort by existing duplicate number for stability
            if ($subgroup === 0) {
                $aDupNum = $this->extractDuplicateNumber($a->file);
                $bDupNum = $this->extractDuplicateNumber($b->file);

                if ($aDupNum !== null && $bDupNum !== null) {
                    return $aDupNum <=> $bDupNum;
                }

                if ($aDupNum !== null) {
                    return 1; // dup after non-dup
                }

                if ($bDupNum !== null) {
                    return -1;
                }

                return ($a->clusterRank ?? PHP_INT_MAX) <=> ($b->clusterRank ?? PHP_INT_MAX);
            }

            $expectedBasename = sprintf('%s-%03d', $groupKey, $subgroup);

            // P1: Idempotent match — source basename equals clean subgroup name
            $aMatch = FileHelper::basenameWithoutExtension($a->file) === $expectedBasename;
            $bMatch = FileHelper::basenameWithoutExtension($b->file) === $expectedBasename;

            if ($aMatch && !$bMatch) {
                return -1;
            }

            if (!$aMatch && $bMatch) {
                return 1;
            }

            // P2: Existing duplicate number — lower number first
            $aDupNum = $this->extractDuplicateNumber($a->file);
            $bDupNum = $this->extractDuplicateNumber($b->file);

            if ($aDupNum !== null && $bDupNum !== null) {
                return $aDupNum <=> $bDupNum;
            }

            if ($aDupNum !== null) {
                return 1; // item with dup number after item without
            }

            if ($bDupNum !== null) {
                return -1;
            }

            // P3: clusterRank from SubgroupClassifier
            return ($a->clusterRank ?? PHP_INT_MAX) <=> ($b->clusterRank ?? PHP_INT_MAX);
        });

        // Cross-directory conflict resolution: count non-Companion files per directory
        $canonicalDir = ($canonical instanceof AssetItem) ? $canonical->file->getPath() : null;

        /** @var array<string, int> $dirFileCounts */
        $dirFileCounts = [];

        foreach ($items as $item) {
            if ($item->role === ItemRole::Companion) {
                continue;
            }

            $dir                 = $item->file->getPath();
            $dirFileCounts[$dir] = ($dirFileCounts[$dir] ?? 0) + 1;
        }

        // Track duplicate counters per cluster base and a global counter for canonical cluster
        /** @var array<string, int> $clusterDuplicateCounter */
        $clusterDuplicateCounter = [];

        /** @var array<string, bool> $clusterFirstSeen */
        $clusterFirstSeen = [];

        /** @var array<string, int> $canonicalClusterDupCounterByExt */
        $canonicalClusterDupCounterByExt = [];

        foreach ($items as $item) {
            $directory = $item->file->getPath();

            if ($item->role === ItemRole::Companion) {
                $group->replaceItem($item, $this->resolveCompanionItem(
                    $item,
                    $directory,
                    $groupKey,
                    $canonicalExtension,
                    $useFileExtensionFromSource,
                    $subgroupMap,
                ));

                continue;
            }

            $clusterBase    = ($item->clusterId !== null) ? $this->normalizeClusterId($item->clusterId) : null;
            $subgroupNumber = ($clusterBase !== null)
                ? ($subgroupMap[$clusterBase] ?? 0)
                : $unclassifiedSubgroup;

            $isCanonicalCluster = $subgroupNumber === 0;

            // Non-canonical cluster items always keep their own extension because they
            // represent content-distinct files (edits, format conversions).
            $extension = $isCanonicalCluster
                ? $this->resolveExtension($item, $canonicalExtension, $useFileExtensionFromSource)
                : FileHelper::normalizeExtension($item->file->getExtension());

            if ($this->isCrossDirNoConflict($isCanonicalCluster, $directory, $canonicalDir, $dirFileCounts)) {
                $group->replaceItem($item, $item->withProposedName(
                    $this->buildCleanName($directory, $groupKey, $extension),
                ));

                continue;
            }

            if ($isCanonicalCluster) {
                $canonicalClusterDupCounterByExt[$extension] ??= 0;

                $updated = $this->resolveCanonicalClusterItem(
                    $item,
                    $directory,
                    $groupKey,
                    $extension,
                    $canonicalClusterDupCounterByExt[$extension],
                );
            } else {
                $updated = $this->resolveNonCanonicalClusterItem(
                    $item,
                    $directory,
                    $groupKey,
                    $subgroupNumber,
                    $extension,
                    $clusterBase,
                    $clusterFirstSeen,
                    $clusterDuplicateCounter,
                );
            }

            $group->replaceItem($item, $updated);
        }
    }

    /**
     * Resolves the proposed name for a Companion item within subgroup-aware naming.
     *
     * Companions inherit the subgroup suffix of their paired still. Canonical-cluster
     * companions get the clean group key basename; non-canonical-cluster companions
     * get the subgroup suffix (e.g. -002).
     *
     * @param AssetItem          $item                       The companion item
     * @param string             $directory                  Directory part of the target path
     * @param string             $groupKey                   Stable group key used as the basename
     * @param string             $canonicalExtension         Normalized extension of the canonical
     * @param bool               $useFileExtensionFromSource Whether to preserve source extension
     * @param array<string, int> $subgroupMap                Map from cluster base to subgroup number
     */
    private function resolveCompanionItem(
        AssetItem $item,
        string $directory,
        string $groupKey,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
        array $subgroupMap,
    ): AssetItem {
        $companionClusterBase = ($item->clusterId !== null)
            ? $this->normalizeClusterId($item->clusterId)
            : null;
        $companionSubgroup = ($companionClusterBase !== null)
            ? ($subgroupMap[$companionClusterBase] ?? 0)
            : 0;

        $extension    = $this->resolveExtension($item, $canonicalExtension, $useFileExtensionFromSource);
        $proposedName = ($companionSubgroup === 0)
            ? $this->buildCleanName($directory, $groupKey, $extension)
            : $this->buildSubgroupName($directory, $groupKey, $companionSubgroup, $extension);

        return $item->withProposedName($proposedName);
    }

    /**
     * Always returns false: the cross-directory shortcut is disabled.
     *
     * Canonical-cluster duplicates must always receive -duplicate-NNN — an identical
     * copy in another directory is still a duplicate. Non-canonical cluster items must
     * always keep their subgroup suffix for idempotency (without the suffix, a re-run
     * would see a canonical-looking basename and might re-assign the file as canonical).
     *
     * @param array<string, int> $dirFileCounts
     */
    private function isCrossDirNoConflict(
        bool $isCanonicalCluster,
        string $directory,
        ?string $canonicalDir,
        array $dirFileCounts,
    ): bool {
        return false;
    }

    /**
     * Resolves the proposed name for a canonical-cluster item.
     *
     * The canonical item gets the clean basename; other items in the canonical cluster
     * get sequential -duplicate-NNN suffixes.
     *
     * @param AssetItem $item                       The item to resolve
     * @param string    $directory                  Directory part of the target path
     * @param string    $groupKey                   Stable group key used as the basename
     * @param string    $extension                  Normalized file extension
     * @param int       $canonicalClusterDupCounter Running duplicate counter (modified by reference)
     */
    private function resolveCanonicalClusterItem(
        AssetItem $item,
        string $directory,
        string $groupKey,
        string $extension,
        int &$canonicalClusterDupCounter,
    ): AssetItem {
        if ($item->role === ItemRole::Canonical) {
            return $item->withProposedName(
                $this->buildCleanName($directory, $groupKey, $extension),
            );
        }

        ++$canonicalClusterDupCounter;

        return $item->withProposedName(
            $this->buildDuplicateName($directory, $groupKey, $canonicalClusterDupCounter, $extension),
        )->withSequenceNumber($canonicalClusterDupCounter);
    }

    /**
     * Resolves the proposed name for a non-canonical cluster item.
     *
     * The first item in each non-canonical cluster gets the clean subgroup name (-NNN).
     * Subsequent items within the same cluster get -NNN-duplicate-NNN suffixes.
     *
     * @param AssetItem           $item                    The item to resolve
     * @param string              $directory               Directory part of the target path
     * @param string              $groupKey                Stable group key used as the basename
     * @param int                 $subgroupNumber          Subgroup number for the cluster
     * @param string              $extension               Normalized file extension
     * @param string|null         $clusterBase             Normalized cluster base (null for unclassified)
     * @param array<string, bool> $clusterFirstSeen        Tracks first-seen state per cluster (modified by reference)
     * @param array<string, int>  $clusterDuplicateCounter Duplicate counter per cluster (modified by reference)
     */
    private function resolveNonCanonicalClusterItem(
        AssetItem $item,
        string $directory,
        string $groupKey,
        int $subgroupNumber,
        string $extension,
        ?string $clusterBase,
        array &$clusterFirstSeen,
        array &$clusterDuplicateCounter,
    ): AssetItem {
        $subgroupBasename = sprintf('%s-%03d', $groupKey, $subgroupNumber);
        $clusterKey       = $clusterBase ?? '__unclassified__';
        $clusterExtKey    = $clusterKey . ':' . $extension;

        if (!isset($clusterFirstSeen[$clusterExtKey])) {
            // First item with this extension in this subgroup
            $clusterFirstSeen[$clusterExtKey]        = true;
            $clusterDuplicateCounter[$clusterExtKey] = 0;

            return $item->withProposedName(
                $this->buildCleanName($directory, $subgroupBasename, $extension),
            );
        }

        // Duplicate within this subgroup (per extension)
        ++$clusterDuplicateCounter[$clusterExtKey];
        $dupIndex = $clusterDuplicateCounter[$clusterExtKey];

        return $item->withProposedName(
            $this->buildDuplicateName($directory, $subgroupBasename, $dupIndex, $extension),
        )->withSequenceNumber($dupIndex);
    }

    /**
     * Builds a map from normalized cluster base to subgroup number.
     *
     * The canonical cluster gets number 0 (no suffix). Other clusters get sequential
     * numbers starting at 2 (matching old HashSubGroupingService convention).
     *
     * @param list<AssetItem> $items                All items in the group
     * @param string|null     $canonicalClusterBase The canonical item's normalized cluster base
     *
     * @return array<string, int> Map from normalized cluster base to subgroup number
     */
    private function buildSubgroupMap(array $items, ?string $canonicalClusterBase): array
    {
        // Collect distinct non-companion cluster bases
        $seenBases = [];

        foreach ($items as $item) {
            if ($item->role === ItemRole::Companion) {
                continue;
            }

            if ($item->clusterId !== null) {
                $base = $this->normalizeClusterId($item->clusterId);

                if (!isset($seenBases[$base])) {
                    $seenBases[$base] = true;
                }
            }
        }

        // Sort cluster bases alphabetically for stable subgroup numbering regardless
        // of item insertion order. Cluster bases are hash-derived and rename-independent.
        $sortedBases = array_keys($seenBases);
        sort($sortedBases);

        /** @var array<string, int> $map */
        $map = [];

        $nextNumber = 2;

        foreach ($sortedBases as $base) {
            if ($base === $canonicalClusterBase) {
                $map[$base] = 0;
            } else {
                $map[$base] = $nextNumber;
                ++$nextNumber;
            }
        }

        return $map;
    }

    /**
     * Strips -duplicate-NNN suffixes from a clusterId to get the cluster base.
     *
     * SubgroupClassifier assigns clusterIds that include per-file duplicate suffixes
     * (e.g., "key-002-duplicate-001"). The cluster identity is determined by the prefix
     * before any -duplicate- suffix.
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
     * Extracts the duplicate number from a filename, e.g. "foo-duplicate-003.jpg" returns 3.
     * Returns null if no duplicate suffix is found.
     *
     * @param SplFileInfo $file File to extract the duplicate number from
     *
     * @return int|null The duplicate number, or null when no -duplicate-NNN suffix exists
     */
    private function extractDuplicateNumber(SplFileInfo $file): ?int
    {
        $basename = FileHelper::basenameWithoutExtension($file);

        if (preg_match('/-duplicate-(\d+)$/', $basename, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
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
     * Builds a target pathname with a subgroup suffix (e.g. "key-002").
     *
     * @param string $directory      Directory part of the target path
     * @param string $groupKey       Group key used as the basename prefix
     * @param int    $subgroupNumber Sequential subgroup number (e.g. 2 → "-002")
     * @param string $extension      Normalized file extension (without leading dot)
     */
    private function buildSubgroupName(string $directory, string $groupKey, int $subgroupNumber, string $extension): string
    {
        return $directory . DIRECTORY_SEPARATOR . sprintf('%s-%03d', $groupKey, $subgroupNumber) . '.' . $extension;
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
