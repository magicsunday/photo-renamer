<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use Closure;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\ItemRole;
use SplFileInfo;

use function array_keys;
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
 * Resolves subgroup-aware target names once a group contains multiple content clusters.
 *
 * TargetNameResolver keeps the simple flat-naming path, while this collaborator owns
 * the cluster-sensitive naming rules: canonical-cluster naming, subgroup numbering,
 * subgroup duplicate suffixes, and companion inheritance of subgroup suffixes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SubgroupNameResolver
{
    /**
     * Resolves names using subgroup-aware logic when multiple clusterIds exist.
     *
     * Items in the canonical cluster get clean basenames (canonical) or
     * -duplicate-NNN suffixes. Items in other clusters get -NNN subgroup suffixes,
     * with additional -duplicate-NNN suffixes for second and subsequent items
     * within the same cluster.
     *
     * @param AssetGroup                               $group                      Group to resolve
     * @param AssetItem|null                           $canonical                  The canonical item (if any)
     * @param string                                   $canonicalExtension         Normalized extension of the canonical
     * @param bool                                     $useFileExtensionFromSource Whether to preserve source extension
     * @param Closure(AssetItem, string, bool): string $extensionResolver          Callback that resolves extensions consistently with the enclosing TargetNameResolver
     */
    public function resolve(
        AssetGroup $group,
        ?AssetItem $canonical,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
        Closure $extensionResolver,
    ): void {
        $items    = $group->getItems();
        $groupKey = $group->groupKey;

        $canonicalClusterBase = ($canonical instanceof AssetItem && $canonical->clusterId !== null)
            ? $this->normalizeClusterId($canonical->clusterId)
            : null;

        $subgroupMap          = $this->buildSubgroupMap($items, $canonicalClusterBase);
        $unclassifiedSubgroup = $subgroupMap !== []
            ? max($subgroupMap) + 1
            : 2;

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
        usort($items, function (AssetItem $itemA, AssetItem $itemB) use ($subgroupMap, $groupKey, $unclassifiedSubgroup): int {
            if ($itemA->role === ItemRole::Companion || $itemB->role === ItemRole::Companion) {
                return 0;
            }

            if ($itemA->role === ItemRole::Canonical || $itemB->role === ItemRole::Canonical) {
                if ($itemA->role === ItemRole::Canonical) {
                    return -1;
                }

                return 1;
            }

            $aCluster = ($itemA->clusterId !== null) ? $this->normalizeClusterId($itemA->clusterId) : null;
            $bCluster = ($itemB->clusterId !== null) ? $this->normalizeClusterId($itemB->clusterId) : null;

            if ($aCluster !== $bCluster) {
                return 0;
            }

            $subgroup = ($aCluster !== null)
                ? ($subgroupMap[$aCluster] ?? 0)
                : $unclassifiedSubgroup;

            if ($subgroup === 0) {
                $aDupNum = $this->extractDuplicateNumber($itemA->file);
                $bDupNum = $this->extractDuplicateNumber($itemB->file);

                if ($aDupNum !== null && $bDupNum !== null) {
                    return $aDupNum <=> $bDupNum;
                }

                if ($aDupNum !== null) {
                    return 1;
                }

                if ($bDupNum !== null) {
                    return -1;
                }

                return ($itemA->clusterRank ?? PHP_INT_MAX) <=> ($itemB->clusterRank ?? PHP_INT_MAX);
            }

            $expectedBasename = sprintf('%s-%03d', $groupKey, $subgroup);
            $aMatch           = FileHelper::basenameWithoutExtension($itemA->file) === $expectedBasename;
            $bMatch           = FileHelper::basenameWithoutExtension($itemB->file) === $expectedBasename;

            if ($aMatch && !$bMatch) {
                return -1;
            }

            if (!$aMatch && $bMatch) {
                return 1;
            }

            $aDupNum = $this->extractDuplicateNumber($itemA->file);
            $bDupNum = $this->extractDuplicateNumber($itemB->file);

            if ($aDupNum !== null && $bDupNum !== null) {
                return $aDupNum <=> $bDupNum;
            }

            if ($aDupNum !== null) {
                return 1;
            }

            if ($bDupNum !== null) {
                return -1;
            }

            return ($itemA->clusterRank ?? PHP_INT_MAX) <=> ($itemB->clusterRank ?? PHP_INT_MAX);
        });

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
                    $extensionResolver,
                ));

                continue;
            }

            $clusterBase    = ($item->clusterId !== null) ? $this->normalizeClusterId($item->clusterId) : null;
            $subgroupNumber = ($clusterBase !== null)
                ? ($subgroupMap[$clusterBase] ?? 0)
                : $unclassifiedSubgroup;
            $isCanonicalCluster = $subgroupNumber === 0;

            $extension = $isCanonicalCluster
                ? $extensionResolver($item, $canonicalExtension, $useFileExtensionFromSource)
                : FileHelper::normalizeExtension($item->file->getExtension());

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
     * @param AssetItem                                $item                       The companion item
     * @param string                                   $directory                  Directory part of the target path
     * @param string                                   $groupKey                   Stable group key used as the basename
     * @param string                                   $canonicalExtension         Normalized extension of the canonical
     * @param bool                                     $useFileExtensionFromSource Whether to preserve source extension
     * @param array<string, int>                       $subgroupMap                Map from cluster base to subgroup number
     * @param Closure(AssetItem, string, bool): string $extensionResolver          Callback that resolves extensions consistently with the enclosing TargetNameResolver
     */
    private function resolveCompanionItem(
        AssetItem $item,
        string $directory,
        string $groupKey,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
        array $subgroupMap,
        Closure $extensionResolver,
    ): AssetItem {
        $companionClusterBase = ($item->clusterId !== null)
            ? $this->normalizeClusterId($item->clusterId)
            : null;
        $companionSubgroup = ($companionClusterBase !== null)
            ? ($subgroupMap[$companionClusterBase] ?? 0)
            : 0;

        $extension    = $extensionResolver($item, $canonicalExtension, $useFileExtensionFromSource);
        $proposedName = ($companionSubgroup === 0)
            ? $this->buildCleanName($directory, $groupKey, $extension)
            : $this->buildSubgroupName($directory, $groupKey, $companionSubgroup, $extension);

        return $item->withProposedName($proposedName);
    }

    /**
     * Resolves the proposed name for a canonical-cluster item.
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
            $clusterFirstSeen[$clusterExtKey]        = true;
            $clusterDuplicateCounter[$clusterExtKey] = 0;

            return $item->withProposedName(
                $this->buildCleanName($directory, $subgroupBasename, $extension),
            );
        }

        ++$clusterDuplicateCounter[$clusterExtKey];
        $dupIndex = $clusterDuplicateCounter[$clusterExtKey];

        return $item->withProposedName(
            $this->buildDuplicateName($directory, $subgroupBasename, $dupIndex, $extension),
        )->withSequenceNumber($dupIndex);
    }

    /**
     * Builds a map from normalized cluster base to subgroup number.
     *
     * @param list<AssetItem> $items                All items in the group
     * @param string|null     $canonicalClusterBase The canonical item's normalized cluster base
     *
     * @return array<string, int> Map from normalized cluster base to subgroup number
     */
    private function buildSubgroupMap(array $items, ?string $canonicalClusterBase): array
    {
        $seenBases = [];

        foreach ($items as $item) {
            if ($item->role === ItemRole::Companion) {
                continue;
            }

            if ($item->clusterId !== null) {
                $base = $this->normalizeClusterId($item->clusterId);
                $seenBases[$base] ??= true;
            }
        }

        $sortedBases = array_keys($seenBases);
        sort($sortedBases);

        /** @var array<string, int> $map */
        $map        = [];
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
