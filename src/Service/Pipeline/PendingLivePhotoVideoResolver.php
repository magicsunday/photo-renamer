<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use SplFileInfo;

/**
 * Resolves deferred Live Photo videos that never found a still-image anchor.
 *
 * CaptureGroupBuilder defers videos with content identifiers so they can inherit
 * the still image's group when one appears later. This collaborator handles the
 * fallback path after the main scan: unresolved pending videos are replayed into
 * date-based groups derived from their remembered fallback target.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class PendingLivePhotoVideoResolver
{
    /**
     * Resolves remaining pending video companions that have no paired still image.
     *
     * @param CaptureGroupBuildState               $state                       Build-time state with content identifier cache
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy Strategy to generate grouping keys
     * @param AssetGroupCollection                 $collection                  Collection to add resolved groups to
     */
    public function resolve(
        CaptureGroupBuildState $state,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        AssetGroupCollection $collection,
    ): void {
        foreach ($state->contentIdentifierCache as $cacheEntry) {
            if (!$cacheEntry->hasPendingFiles()) {
                continue;
            }

            if (!$cacheEntry->getTarget() instanceof SplFileInfo) {
                continue;
            }

            $targetFileInfo = $cacheEntry->getTarget();
            $pendingFiles   = $cacheEntry->getPendingFiles();

            try {
                $duplicateIdentifier = $duplicateIdentifierStrategy->generateIdentifier(
                    $pendingFiles[0],
                    $targetFileInfo,
                );
            } catch (HashComputationException) {
                continue;
            }

            if ($duplicateIdentifier === false) {
                continue;
            }

            if ($collection->has($duplicateIdentifier)) {
                $group = $collection->get($duplicateIdentifier);

                if (!$group instanceof AssetGroup) {
                    continue;
                }
            } else {
                $group = new AssetGroup($duplicateIdentifier);
            }

            foreach ($pendingFiles as $pendingFile) {
                $pendingItem = new AssetItem($pendingFile);
                $pendingItem = $pendingItem->withMetadata(
                    $state->temporalMetadataMap[$pendingFile->getPathname()] ?? null,
                    $state->contentIdentifierMap[$pendingFile->getPathname()] ?? null,
                );
                $group->addItem($pendingItem);
            }

            if (!$collection->has($duplicateIdentifier)) {
                $collection->set($duplicateIdentifier, $group);
            }
        }
    }
}
