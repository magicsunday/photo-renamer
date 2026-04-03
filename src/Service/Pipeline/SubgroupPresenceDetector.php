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
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\ItemRole;

use function array_unique;
use function count;
use function preg_quote;
use function preg_replace;

/**
 * Detects whether a group contains multiple effective subgroups.
 *
 * Target naming only needs one decision here: stay on the flat naming path or
 * switch into subgroup-aware naming. Centralizing that policy keeps
 * TargetNameResolver focused on orchestration and makes the partial-classification
 * edge case directly testable.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SubgroupPresenceDetector
{
    /**
     * Returns true when non-Companion items have more than one distinct cluster base,
     * or when some items have a clusterId while others do not (partial classification).
     *
     * Normalizes clusterIds by stripping `-duplicate-NNN` suffixes before comparing,
     * because subgroup identity is determined by the stable cluster base rather than
     * the per-file duplicate suffix attached by SubgroupClassifier.
     *
     * @param list<AssetItem> $items All items in the group
     */
    public function hasMultipleSubgroups(array $items): bool
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

        if (count(array_unique($clusterBases)) > 1) {
            return true;
        }

        return $hasNonNullCluster && $hasNullCluster;
    }

    /**
     * Strips `-duplicate-NNN` suffixes from a clusterId to get the stable cluster base.
     *
     * @param string $clusterId Raw clusterId from SubgroupClassifier
     *
     * @return string Normalized cluster base without `-duplicate-NNN` suffixes
     */
    private function normalizeClusterId(string $clusterId): string
    {
        return (string) preg_replace(
            '/' . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '\d+$/',
            '',
            $clusterId,
        );
    }
}
