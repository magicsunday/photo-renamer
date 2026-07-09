<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use Closure;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use SplFileInfo;

use function array_key_exists;

/**
 * Coordinates content-identifier cache behavior during legacy duplicate grouping.
 *
 * The legacy grouping pass still needs the same Live Photo-specific rules as the
 * newer AssetGroup pipeline: initialize cache entries, defer companion videos
 * until a still image anchors the family, replay pending videos into the
 * resolved group, and keep skipped files attached when a content identifier
 * still ties them to a future or existing group.
 *
 * Keeping those rules in one collaborator prevents DuplicateDetectionService
 * from remaining the implementation bucket for pending-file handling.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyContentIdentifierCoordinator
{
    /**
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Distinguishes Live Photo stills from deferred companion videos.
     */
    public function __construct(
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
    }

    /**
     * Ensures a content-identifier cache entry exists and returns it.
     *
     * @param string|null                                $normalizedContentIdentifier Normalized content identifier (null is a no-op)
     * @param array<string, ContentIdentifierCacheEntry> $contentIdentifierCache      Mutable cache keyed by normalized content identifier
     *
     * @return ContentIdentifierCacheEntry|null Shared cache entry for the identifier, or null when none exists
     */
    public function resolveCacheEntry(
        ?string $normalizedContentIdentifier,
        array &$contentIdentifierCache,
    ): ?ContentIdentifierCacheEntry {
        if ($normalizedContentIdentifier === null) {
            return null;
        }

        if (!array_key_exists($normalizedContentIdentifier, $contentIdentifierCache)) {
            $contentIdentifierCache[$normalizedContentIdentifier] = new ContentIdentifierCacheEntry();
        }

        return $contentIdentifierCache[$normalizedContentIdentifier];
    }

    /**
     * Defers a companion video until its still image resolves the content-identifier family.
     *
     * @param SplFileInfo                 $sourceFileInfo              Source file being processed
     * @param TargetFileResult            $result                      Successful target result used for fallback-target caching
     * @param FileDuplicateCollection     $fileDuplicateCollection     Collection of discovered duplicate groups
     * @param ContentIdentifierCacheEntry $contentIdentifierCacheEntry Cache entry for the current content identifier
     *
     * @return bool True when the caller should skip normal grouping because the file was deferred or attached directly
     */
    public function handleDeferredVideoCompanion(
        SplFileInfo $sourceFileInfo,
        TargetFileResult $result,
        FileDuplicateCollection $fileDuplicateCollection,
        ContentIdentifierCacheEntry $contentIdentifierCacheEntry,
    ): bool {
        if ($this->mediaTypeClassifier->isLivePhotoStill($sourceFileInfo)) {
            return false;
        }

        $cachedDuplicateIdentifier = $contentIdentifierCacheEntry->getDuplicateIdentifier();

        if (
            is_string($cachedDuplicateIdentifier)
            && $fileDuplicateCollection->has($cachedDuplicateIdentifier)
        ) {
            $existingDuplicate = $fileDuplicateCollection->get($cachedDuplicateIdentifier);

            if ($existingDuplicate instanceof FileDuplicate) {
                $existingDuplicate->addFile($sourceFileInfo);
            }
        } else {
            $this->queuePendingFile(
                $contentIdentifierCacheEntry,
                $sourceFileInfo,
                $result->getTargetFile(),
            );
        }

        return true;
    }

    /**
     * Handles a skipped or errored file when a content identifier may still tie it to a group.
     *
     * @param SplFileInfo                      $sourceFileInfo              Source file being processed
     * @param TargetFileResult                 $result                      Skipped/error result produced by the rename strategy
     * @param FileDuplicateCollection          $fileDuplicateCollection     Collection of discovered duplicate groups
     * @param ContentIdentifierCacheEntry|null $contentIdentifierCacheEntry Cache entry for the current content identifier, if one exists
     * @param list<SkippedFile>                $skippedFiles                Mutable skipped-file list used by the legacy service
     */
    public function handleSkippedFile(
        SplFileInfo $sourceFileInfo,
        TargetFileResult $result,
        FileDuplicateCollection $fileDuplicateCollection,
        ?ContentIdentifierCacheEntry $contentIdentifierCacheEntry,
        array &$skippedFiles,
    ): void {
        if ($contentIdentifierCacheEntry instanceof ContentIdentifierCacheEntry) {
            $cachedDuplicateIdentifier = $contentIdentifierCacheEntry->getDuplicateIdentifier();

            if (
                is_string($cachedDuplicateIdentifier)
                && $fileDuplicateCollection->has($cachedDuplicateIdentifier)
            ) {
                $existingDuplicate = $fileDuplicateCollection->get($cachedDuplicateIdentifier);

                if ($existingDuplicate instanceof FileDuplicate) {
                    $existingDuplicate->addFile($sourceFileInfo);
                }

                return;
            }

            $contentIdentifierCacheEntry->addPendingFile($sourceFileInfo);

            return;
        }

        $skippedFiles[] = new SkippedFile(
            $sourceFileInfo,
            $result->getSkipReason() ?? 'no capture date',
            $result->isError(),
        );
    }

    /**
     * Queues a file as pending when no duplicate identifier could be generated yet.
     *
     * @param SplFileInfo                      $sourceFileInfo              Source file whose grouping key could not be computed
     * @param SplFileInfo                      $targetFileInfo              Already resolved fallback target for later recovery
     * @param ContentIdentifierCacheEntry|null $contentIdentifierCacheEntry Cache entry for the current content identifier, if one exists
     */
    public function queuePendingFileForUnresolvedIdentifier(
        SplFileInfo $sourceFileInfo,
        SplFileInfo $targetFileInfo,
        ?ContentIdentifierCacheEntry $contentIdentifierCacheEntry,
    ): void {
        if (!$contentIdentifierCacheEntry instanceof ContentIdentifierCacheEntry) {
            return;
        }

        $this->queuePendingFile($contentIdentifierCacheEntry, $sourceFileInfo, $targetFileInfo);
    }

    /**
     * Attaches a resolved file to its group and replays pending files for the same content identifier.
     *
     * @param string                           $duplicateIdentifier         Resolved duplicate identifier for the current group
     * @param FileDuplicate                    $fileDuplicate               Target duplicate group
     * @param SplFileInfo                      $targetFileInfo              Resolved target for the group anchor
     * @param ContentIdentifierCacheEntry|null $contentIdentifierCacheEntry Cache entry for the current content identifier, if one exists
     */
    public function attachToResolvedGroup(
        string $duplicateIdentifier,
        FileDuplicate $fileDuplicate,
        SplFileInfo $targetFileInfo,
        ?ContentIdentifierCacheEntry $contentIdentifierCacheEntry,
    ): void {
        if (!$contentIdentifierCacheEntry instanceof ContentIdentifierCacheEntry) {
            return;
        }

        $contentIdentifierCacheEntry->rememberResolvedGroup(
            $duplicateIdentifier,
            $fileDuplicate->getTarget(),
        );

        foreach ($contentIdentifierCacheEntry->getPendingFiles() as $pendingFile) {
            $fileDuplicate->addFile($pendingFile);
        }

        $contentIdentifierCacheEntry->clearPendingFiles();
    }

    /**
     * Replays still-unresolved pending files into fallback groups after the main scan pass.
     *
     * @param array<string, ContentIdentifierCacheEntry> $contentIdentifierCache  Cache entries collected during grouping
     * @param FileDuplicateCollection                    $fileDuplicateCollection Collection of discovered duplicate groups
     * @param Closure(SplFileInfo, SplFileInfo): ?string $identifierGenerator     Generates a duplicate identifier from source file + fallback target
     */
    public function resolvePendingFallbackGroups(
        array $contentIdentifierCache,
        FileDuplicateCollection $fileDuplicateCollection,
        Closure $identifierGenerator,
    ): void {
        foreach ($contentIdentifierCache as $cacheEntry) {
            if (!$cacheEntry->hasPendingFiles()) {
                continue;
            }

            $targetFileInfo = $cacheEntry->getTarget();

            if (!$targetFileInfo instanceof SplFileInfo) {
                continue;
            }

            $duplicateIdentifier = $identifierGenerator(
                $cacheEntry->getPendingFiles()[0],
                $targetFileInfo,
            );

            if ($duplicateIdentifier === null) {
                continue;
            }

            if ($fileDuplicateCollection->has($duplicateIdentifier)) {
                $fileDuplicate = $fileDuplicateCollection->get($duplicateIdentifier);

                if (!$fileDuplicate instanceof FileDuplicate) {
                    continue;
                }
            } else {
                $fileDuplicate = new FileDuplicate()->setTarget($targetFileInfo);
            }

            foreach ($cacheEntry->getPendingFiles() as $pendingFile) {
                $fileDuplicate->addFile($pendingFile);
            }

            if (!$fileDuplicateCollection->has($duplicateIdentifier)) {
                $fileDuplicateCollection->set($duplicateIdentifier, $fileDuplicate);
            }
        }
    }

    /**
     * Queues a pending file and remembers the first fallback target that became available.
     *
     * @param ContentIdentifierCacheEntry $contentIdentifierCacheEntry Cache entry tracking one Live Photo family
     * @param SplFileInfo                 $sourceFileInfo              File to queue for later resolution
     * @param SplFileInfo|null            $targetFileInfo              Optional fallback target to preserve for the pending file
     */
    private function queuePendingFile(
        ContentIdentifierCacheEntry $contentIdentifierCacheEntry,
        SplFileInfo $sourceFileInfo,
        ?SplFileInfo $targetFileInfo,
    ): void {
        $contentIdentifierCacheEntry->addPendingFile($sourceFileInfo);

        if ($targetFileInfo instanceof SplFileInfo) {
            $contentIdentifierCacheEntry->rememberFallbackTarget($targetFileInfo);
        }
    }
}
