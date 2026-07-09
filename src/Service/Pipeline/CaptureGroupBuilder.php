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
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\Filesystem\SortedFileIteratorCollector;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetectorInterface;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingServiceInterface;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use MagicSunday\Renamer\Service\TargetFileResolver;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function assert;
use function count;

/**
 * Collects files from a directory iterator, extracts metadata using the rename
 * strategy, and groups them into capture groups keyed by duplicate identifier.
 *
 * Mirrors the grouping logic from DuplicateDetectionService::groupFilesByDuplicateIdentifier()
 * but outputs AssetGroupCollection instead of FileDuplicateCollection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CaptureGroupBuilder implements CaptureGroupBuilderInterface
{
    /**
     * @param ProgressReporterInterface               $progressReporter                    Narrow reporting boundary for progress headings and diagnostics
     * @param LivePhotoConflictDetectorInterface|null $livePhotoConflictDetector           LP conflict detection (optional)
     * @param LivePhotoPairingServiceInterface|null   $livePhotoPairingService             LP second-pass pairing (optional)
     * @param TargetFileResolver                      $targetFileResolver                  Resolves generated filenames into success/skip/error target results.
     * @param CaptureAssetCandidateExtractor          $captureAssetCandidateExtractor      Extracts AssetItem candidates and records metadata/content-ID state.
     * @param CaptureContentIdentifierCoordinator     $captureContentIdentifierCoordinator Coordinates Live Photo content-ID cache, pending files, and skipped-file attachment rules.
     * @param PendingLivePhotoVideoResolver           $pendingLivePhotoVideoResolver       Resolves deferred videos that never found a still-image anchor.
     * @param CaptureGroupQualityTracker              $captureGroupQualityTracker          Records fallback/timezone quality flags separately from grouping.
     */
    public function __construct(
        private ProgressReporterInterface $progressReporter,
        private ?LivePhotoConflictDetectorInterface $livePhotoConflictDetector,
        private ?LivePhotoPairingServiceInterface $livePhotoPairingService,
        private TargetFileResolver $targetFileResolver,
        private CaptureAssetCandidateExtractor $captureAssetCandidateExtractor,
        private CaptureContentIdentifierCoordinator $captureContentIdentifierCoordinator,
        private PendingLivePhotoVideoResolver $pendingLivePhotoVideoResolver,
        private CaptureGroupQualityTracker $captureGroupQualityTracker,
    ) {
    }

    /**
     * Collects files from the iterator, extracts metadata, and groups them into
     * capture groups keyed by duplicate identifier. Populates the given context
     * with quality flags (fallback dates, ambiguous timezones) and disk index.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner>    $iterator                    Iterator yielding candidate files
     * @param RenameStrategyInterface              $renameStrategy              Strategy to compute target filenames
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy Strategy to generate grouping keys
     * @param PipelineContext                      $context                     Mutable state bag for pipeline phases
     *
     * @return AssetGroupCollection Collection of capture groups
     */
    #[Override]
    public function build(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        PipelineContext $context,
    ): AssetGroupCollection {
        $state = new CaptureGroupBuildState();

        // Step 1: Collect and sort files by directory depth then pathname
        $files = SortedFileIteratorCollector::collectAndSortFiles($iterator);

        // Step 2: Build disk index in PipelineContext
        foreach ($files as $file) {
            $context->markOccupied($file->getPathname());
            $state->filesByPath[$file->getPathname()] = $file;
        }

        // Step 3: Set scanned file count
        $context->setScannedFileCount(count($files));

        // Step 4: Show progress bar
        $this->progressReporter->text('<fg=cyan>Scanning files</>');
        $this->progressReporter->startProgress(count($files));

        $collection = new AssetGroupCollection();

        foreach ($files as $sourceFileInfo) {
            $result = $this->getTargetFileResult($sourceFileInfo, $renameStrategy, $context);

            $item                        = $this->captureAssetCandidateExtractor->extract($sourceFileInfo, $renameStrategy, $state);
            $normalizedContentIdentifier = $item->contentIdentifier;

            $this->captureContentIdentifierCoordinator->initializeCacheEntry($normalizedContentIdentifier, $state);

            if ($result->isSkipped()) {
                $this->captureContentIdentifierCoordinator->handleSkippedFile(
                    $sourceFileInfo,
                    $result,
                    $collection,
                    $context,
                    $state,
                    $normalizedContentIdentifier,
                );
                $this->progressReporter->advance();

                continue;
            }

            $this->captureGroupQualityTracker->track($sourceFileInfo, $renameStrategy, $context);

            if ($this->captureContentIdentifierCoordinator->handleDeferredVideoCompanion(
                $sourceFileInfo,
                $item,
                $result,
                $collection,
                $state,
                $normalizedContentIdentifier,
            )) {
                $this->progressReporter->advance();

                continue;
            }

            $duplicateIdentifier = $this->generateDuplicateIdentifier(
                $sourceFileInfo,
                $result,
                $duplicateIdentifierStrategy,
                $state,
                $normalizedContentIdentifier,
            );

            if ($duplicateIdentifier === null) {
                $this->progressReporter->advance();

                continue;
            }

            $this->captureContentIdentifierCoordinator->attachToResolvedGroup(
                $item,
                $duplicateIdentifier,
                $result,
                $collection,
                $state,
                $normalizedContentIdentifier,
            );

            $this->progressReporter->advance();
        }

        $this->progressReporter->finish();

        // Resolve remaining pending video companions without paired still images
        $this->pendingLivePhotoVideoResolver->resolve(
            $state,
            $duplicateIdentifierStrategy,
            $collection,
        );

        // Live Photo second-pass pairing: re-scan the iterator for MOV companions
        // that were missed during the main loop (e.g. no EXIF date, only matchable
        // via content identifier after the still image has been processed).
        if ($this->livePhotoPairingService instanceof LivePhotoPairingServiceInterface) {
            $this->performLivePhotoSecondPass(
                $iterator,
                $collection,
                $renameStrategy,
                $context,
            );
        }

        // LP conflict detection
        if ($this->livePhotoConflictDetector instanceof LivePhotoConflictDetectorInterface) {
            $conflictFiles = $this->livePhotoConflictDetector->detectConflictFiles(
                $state->filesByPath,
                $state->temporalMetadataMap,
            );

            foreach (array_keys($conflictFiles) as $pathname) {
                $context->addLivePhotoConflictFile($pathname);
            }
        }

        return $collection;
    }

    /**
     * Computes a target file result from the rename strategy for a given source file.
     *
     * @param SplFileInfo             $sourceFileInfo Source file to compute target for
     * @param RenameStrategyInterface $renameStrategy Strategy to generate the target filename
     * @param PipelineContext         $context        Pipeline context for source directory
     *
     * @return TargetFileResult Result containing target file or skip reason
     */
    private function getTargetFileResult(
        SplFileInfo $sourceFileInfo,
        RenameStrategyInterface $renameStrategy,
        PipelineContext $context,
    ): TargetFileResult {
        return $this->targetFileResolver->resolve(
            $context->sourceDirectory,
            $sourceFileInfo,
            $renameStrategy,
        );
    }

    /**
     * Generates a duplicate identifier for the given file, handling errors and
     * the false-return case where the strategy cannot produce an identifier.
     *
     * When the strategy returns false and a content identifier cache entry exists,
     * the file is queued as pending for later resolution.
     *
     * @param SplFileInfo                          $file                        Source file
     * @param TargetFileResult                     $result                      Target file result (guaranteed non-skipped)
     * @param DuplicateIdentifierStrategyInterface $strategy                    Strategy to generate grouping keys
     * @param CaptureGroupBuildState               $state                       Mutable build-time state
     * @param string|null                          $normalizedContentIdentifier Normalized content identifier for cache lookup
     *
     * @return string|null Duplicate identifier, or null if generation failed or was deferred
     */
    private function generateDuplicateIdentifier(
        SplFileInfo $file,
        TargetFileResult $result,
        DuplicateIdentifierStrategyInterface $strategy,
        CaptureGroupBuildState $state,
        ?string $normalizedContentIdentifier,
    ): ?string {
        // Guaranteed non-null after isSkipped() guard in caller
        $targetFileInfo = $result->getTargetFile();
        assert($targetFileInfo instanceof SplFileInfo);

        try {
            $duplicateIdentifier = $strategy->generateIdentifier($file, $targetFileInfo);
        } catch (HashComputationException $exception) {
            $this->progressReporter->error($exception->getMessage());

            return null;
        }

        if ($duplicateIdentifier === false) {
            $this->captureContentIdentifierCoordinator->queuePendingFileForUnresolvedIdentifier(
                $file,
                $targetFileInfo,
                $state,
                $normalizedContentIdentifier,
            );

            return null;
        }

        return $duplicateIdentifier;
    }

    /**
     * Performs a second iterator scan to discover MOV companions that were not matched
     * during the main grouping pass. These files typically lack an EXIF date and can
     * only be paired via their Apple content identifier after the still image has been
     * processed.
     *
     * Builds a temporary FileDuplicateCollection bridge, delegates discovery to the
     * LivePhotoPairingService, then merges results back into the AssetGroupCollection.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator       Iterator yielding candidate files (will be rewound)
     * @param AssetGroupCollection              $collection     Collection of discovered capture groups
     * @param RenameStrategyInterface           $renameStrategy Strategy to compute target filenames
     * @param PipelineContext                   $context        Pipeline context for disk index updates
     */
    private function performLivePhotoSecondPass(
        RecursiveIteratorIterator $iterator,
        AssetGroupCollection $collection,
        RenameStrategyInterface $renameStrategy,
        PipelineContext $context,
    ): void {
        assert($this->livePhotoPairingService instanceof LivePhotoPairingServiceInterface);

        // Build temporary FileDuplicateCollection bridge for the pairing service
        $tempCollection = new FileDuplicateCollection();

        foreach ($collection as $key => $group) {
            $fileDuplicate = new FileDuplicate();

            foreach ($group->getItems() as $item) {
                $fileDuplicate->addFile($item->file);
            }

            // Build a target SplFileInfo from the group's first item so the pairing
            // service can derive the target basename for companion matching.
            $firstItem  = $group->getItems()[0] ?? null;
            $targetFile = null;

            if ($firstItem !== null) {
                $targetPath = $firstItem->file->getPath()
                    . DIRECTORY_SEPARATOR
                    . $group->groupKey
                    . '.'
                    . FileHelper::normalizeExtension($firstItem->file->getExtension());

                $targetFile = new SplFileInfo($targetPath);
            }

            if ($targetFile instanceof SplFileInfo) {
                $fileDuplicate->setTarget($targetFile);
            }

            $tempCollection->set($key, $fileDuplicate);
        }

        // Rewind the iterator for the second scan
        $iterator->rewind();

        $this->progressReporter->section('<fg=cyan>Pairing Live Photos</>');

        $fileCount = $context->getScannedFileCount();
        $this->progressReporter->startProgress($fileCount);

        // Build the content identifier resolver callback
        $contentIdentifierResolver = null;

        if ($renameStrategy instanceof LivePhotoAwareRenameStrategyInterface) {
            $contentIdentifierResolver = $renameStrategy->getLivePhotoContentIdentifier(...);
        }

        if ($contentIdentifierResolver === null) {
            $this->progressReporter->finish();

            return;
        }

        $pairings = $this->livePhotoPairingService->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $tempCollection,
            contentIdentifierResolver: $contentIdentifierResolver,
            onFileInspected: function (): void {
                $this->progressReporter->advance();
            },
        );

        // Merge pairings back into AssetGroupCollection
        foreach ($pairings as $pairing) {
            $duplicateIdentifier = $pairing->getDuplicateIdentifier();
            $group               = $collection->get($duplicateIdentifier);

            if ($group instanceof AssetGroup) {
                $newItem = new AssetItem($pairing->getSourceFile());

                // Populate metadata if the rename strategy supports it
                if ($renameStrategy instanceof MetadataAwareRenameStrategyInterface) {
                    try {
                        $temporalMetadata = $renameStrategy->getTemporalMetadata($pairing->getSourceFile());
                    } catch (TargetFilenameException) {
                        $temporalMetadata = null;
                    }

                    $contentId = null;

                    try {
                        $contentId = $renameStrategy->getLivePhotoContentIdentifier($pairing->getSourceFile());
                    } catch (TargetFilenameException) {
                        $contentId = null;
                    }

                    $newItem = $newItem->withMetadata($temporalMetadata, $contentId);
                }

                $group->addItem($newItem);
                $context->markOccupied($pairing->getSourceFile()->getPathname());

                continue;
            }

            // New group for this pairing (rare: companion found but no existing group)
            $newGroup = new AssetGroup($duplicateIdentifier);
            $newItem  = new AssetItem($pairing->getSourceFile());
            $newGroup->addItem($newItem);
            $collection->set($duplicateIdentifier, $newGroup);
            $context->markOccupied($pairing->getSourceFile()->getPathname());
        }

        $this->progressReporter->finish();
    }
}
