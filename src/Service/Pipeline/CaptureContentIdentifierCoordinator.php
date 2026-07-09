<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\ContentIdentifierCacheEntry;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use SplFileInfo;

use function array_key_exists;
use function assert;
use function is_string;

/**
 * Coordinates content-identifier cache behavior during capture-group assembly.
 *
 * Live Photo grouping needs several tightly related rules: initialize cache
 * entries, defer video companions until a still image anchors the group, attach
 * queued files once a group resolves, and treat skipped files conservatively when
 * a content identifier is still present. Keeping those rules together prevents
 * CaptureGroupBuilder from becoming the implementation bucket for pending-file logic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CaptureContentIdentifierCoordinator
{
    /**
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Distinguishes Live Photo stills from deferred companion videos.
     */
    public function __construct(
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
    }

    /**
     * Ensures a content identifier cache entry exists for the given identifier.
     *
     * @param string|null            $normalizedContentIdentifier Normalized content identifier (null is a no-op)
     * @param CaptureGroupBuildState $state                       Mutable build-time state containing the cache
     */
    public function initializeCacheEntry(
        ?string $normalizedContentIdentifier,
        CaptureGroupBuildState $state,
    ): void {
        if ($normalizedContentIdentifier === null) {
            return;
        }

        if (array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            return;
        }

        $state->contentIdentifierCache[$normalizedContentIdentifier] = new ContentIdentifierCacheEntry();
    }

    /**
     * Defers a video companion with a content identifier for later Live Photo pairing.
     *
     * Videos with a content identifier are either added directly to an existing group
     * (when the still image has already been processed) or queued as pending (when the
     * still image has not yet been seen).
     *
     * @param SplFileInfo            $file                        Source file to check
     * @param AssetItem              $item                        Asset item with metadata attached
     * @param TargetFileResult       $result                      Target file result for fallback target
     * @param AssetGroupCollection   $collection                  Collection of discovered capture groups
     * @param CaptureGroupBuildState $state                       Mutable build-time state
     * @param string|null            $normalizedContentIdentifier Normalized content identifier for cache lookup
     *
     * @return bool True if the file was deferred (caller should continue to next file)
     */
    public function handleDeferredVideoCompanion(
        SplFileInfo $file,
        AssetItem $item,
        TargetFileResult $result,
        AssetGroupCollection $collection,
        CaptureGroupBuildState $state,
        ?string $normalizedContentIdentifier,
    ): bool {
        if ($normalizedContentIdentifier === null) {
            return false;
        }

        if (!array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            return false;
        }

        if ($this->mediaTypeClassifier->isLivePhotoStill($file)) {
            return false;
        }

        $cacheEntry                = $state->contentIdentifierCache[$normalizedContentIdentifier];
        $cachedDuplicateIdentifier = $cacheEntry->getDuplicateIdentifier();

        if (
            is_string($cachedDuplicateIdentifier)
            && $collection->has($cachedDuplicateIdentifier)
        ) {
            $existingGroup = $collection->get($cachedDuplicateIdentifier);

            if ($existingGroup instanceof AssetGroup) {
                $existingGroup->addItem($item);
            }
        } else {
            $this->queuePendingFile($cacheEntry, $file, $result->getTargetFile());
        }

        return true;
    }

    /**
     * Queues a file as pending when no duplicate identifier could be generated yet.
     *
     * @param SplFileInfo            $file                        Source file whose grouping key could not be computed
     * @param SplFileInfo            $targetFileInfo              Already resolved fallback target for later recovery
     * @param CaptureGroupBuildState $state                       Mutable build-time state
     * @param string|null            $normalizedContentIdentifier Normalized content identifier for cache lookup
     */
    public function queuePendingFileForUnresolvedIdentifier(
        SplFileInfo $file,
        SplFileInfo $targetFileInfo,
        CaptureGroupBuildState $state,
        ?string $normalizedContentIdentifier,
    ): void {
        if (($normalizedContentIdentifier === null) || !array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            return;
        }

        $cacheEntry = $state->contentIdentifierCache[$normalizedContentIdentifier];
        $this->queuePendingFile($cacheEntry, $file, $targetFileInfo);
    }

    /**
     * Creates or updates the target group and resolves pending files for the same content identifier.
     *
     * @param AssetItem              $item                        Asset item to attach
     * @param string                 $duplicateIdentifier         Grouping key for the capture group
     * @param TargetFileResult       $result                      Target file result (guaranteed non-skipped)
     * @param AssetGroupCollection   $collection                  Collection of discovered capture groups
     * @param CaptureGroupBuildState $state                       Mutable build-time state
     * @param string|null            $normalizedContentIdentifier Normalized content identifier for cache resolution
     */
    public function attachToResolvedGroup(
        AssetItem $item,
        string $duplicateIdentifier,
        TargetFileResult $result,
        AssetGroupCollection $collection,
        CaptureGroupBuildState $state,
        ?string $normalizedContentIdentifier,
    ): void {
        if ($collection->has($duplicateIdentifier)) {
            /** @var AssetGroup $group */
            $group = $collection->get($duplicateIdentifier);
            $group->addItem($item);
        } else {
            $group = new AssetGroup($duplicateIdentifier);
            $group->addItem($item);
            $collection->set($duplicateIdentifier, $group);
        }

        if (($normalizedContentIdentifier === null) || !array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            return;
        }

        $targetFileInfo = $result->getTargetFile();
        assert($targetFileInfo instanceof SplFileInfo);

        $cacheEntry = $state->contentIdentifierCache[$normalizedContentIdentifier];
        $cacheEntry->rememberResolvedGroup($duplicateIdentifier, $targetFileInfo);

        foreach ($cacheEntry->getPendingFiles() as $pendingFile) {
            $pendingItem = new AssetItem($pendingFile);
            $pendingItem = $pendingItem->withMetadata(
                $state->temporalMetadataMap[$pendingFile->getPathname()] ?? null,
                $state->contentIdentifierMap[$pendingFile->getPathname()] ?? null,
            );
            $group->addItem($pendingItem);
        }

        $cacheEntry->clearPendingFiles();
    }

    /**
     * Handles a skipped/error file when a content identifier may still tie it to a group.
     *
     * @param SplFileInfo            $sourceFileInfo              Source file being processed
     * @param TargetFileResult       $result                      Skipped/error result from the rename strategy
     * @param AssetGroupCollection   $collection                  Collection of discovered capture groups
     * @param PipelineContext        $context                     Pipeline context to record skipped files
     * @param CaptureGroupBuildState $state                       Mutable build-time state
     * @param string|null            $normalizedContentIdentifier Normalized content identifier for cache lookup
     */
    public function handleSkippedFile(
        SplFileInfo $sourceFileInfo,
        TargetFileResult $result,
        AssetGroupCollection $collection,
        PipelineContext $context,
        CaptureGroupBuildState $state,
        ?string $normalizedContentIdentifier,
    ): void {
        if (($normalizedContentIdentifier !== null) && array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            $cacheEntry                = $state->contentIdentifierCache[$normalizedContentIdentifier];
            $cachedDuplicateIdentifier = $cacheEntry->getDuplicateIdentifier();

            if (
                is_string($cachedDuplicateIdentifier)
                && $collection->has($cachedDuplicateIdentifier)
            ) {
                $existingGroup = $collection->get($cachedDuplicateIdentifier);

                if ($existingGroup instanceof AssetGroup) {
                    $pathname = $sourceFileInfo->getPathname();
                    $item     = new AssetItem($sourceFileInfo);
                    $item     = $item->withMetadata(
                        $state->temporalMetadataMap[$pathname] ?? null,
                        $state->contentIdentifierMap[$pathname] ?? null,
                    );
                    $existingGroup->addItem($item);
                }

                return;
            }

            $cacheEntry->addPendingFile($sourceFileInfo);

            return;
        }

        $context->addSkippedFile(new SkippedFile(
            $sourceFileInfo,
            $result->getSkipReason() ?? 'no capture date',
            $result->isError(),
        ));
    }

    /**
     * Queues a pending file and remembers its fallback target when available.
     *
     * @param ContentIdentifierCacheEntry $cacheEntry     Cache entry tracking the Live Photo family
     * @param SplFileInfo                 $file           File to queue for later resolution
     * @param SplFileInfo|null            $targetFileInfo Optional fallback target to preserve for the pending file
     */
    private function queuePendingFile(
        ContentIdentifierCacheEntry $cacheEntry,
        SplFileInfo $file,
        ?SplFileInfo $targetFileInfo,
    ): void {
        $cacheEntry->addPendingFile($file);

        if ($targetFileInfo instanceof SplFileInfo) {
            $cacheEntry->rememberFallbackTarget($targetFileInfo);
        }
    }
}
