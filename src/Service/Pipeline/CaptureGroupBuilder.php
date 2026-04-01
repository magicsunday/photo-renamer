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
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetectorInterface;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingServiceInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_key_exists;
use function array_keys;
use function assert;
use function count;
use function is_string;
use function max;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function trim;
use function usort;

use const DIRECTORY_SEPARATOR;

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
     * @param SymfonyStyle                            $io                        Console IO for progress bars and error output
     * @param MediaTypeClassifierInterface            $mediaTypeClassifier       Classifies files by media type (still vs. video)
     * @param LivePhotoConflictDetectorInterface|null $livePhotoConflictDetector LP conflict detection (optional)
     * @param LivePhotoPairingServiceInterface|null   $livePhotoPairingService   LP second-pass pairing (optional)
     */
    public function __construct(
        private SymfonyStyle $io,
        private MediaTypeClassifierInterface $mediaTypeClassifier,
        private ?LivePhotoConflictDetectorInterface $livePhotoConflictDetector = null,
        private ?LivePhotoPairingServiceInterface $livePhotoPairingService = null,
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
        $files = $this->collectAndSortFiles($iterator);

        // Step 2: Build disk index in PipelineContext
        foreach ($files as $file) {
            $context->markOccupied($file->getPathname());
            $state->filesByPath[$file->getPathname()] = $file;
        }

        // Step 3: Set scanned file count
        $context->setScannedFileCount(count($files));

        // Step 4: Show progress bar
        $this->io->text('<fg=cyan>Scanning files</>');
        $progressBar = $this->startProgressBar(count($files));

        $collection = new AssetGroupCollection();

        foreach ($files as $sourceFileInfo) {
            $result = $this->getTargetFileResult($sourceFileInfo, $renameStrategy, $context);

            $item                        = $this->extractAssetCandidate($sourceFileInfo, $renameStrategy, $state);
            $normalizedContentIdentifier = $item->contentIdentifier;

            $this->initContentIdCacheEntry($normalizedContentIdentifier, $state);

            if ($result->isSkipped()) {
                $this->handleSkippedFile($sourceFileInfo, $result, $collection, $context, $state, $normalizedContentIdentifier);
                $progressBar->advance();

                continue;
            }

            $this->trackQualityFlags($sourceFileInfo, $renameStrategy, $context);

            if ($this->deferVideoCompanion($sourceFileInfo, $item, $result, $collection, $state, $normalizedContentIdentifier)) {
                $progressBar->advance();

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
                $progressBar->advance();

                continue;
            }

            $this->attachToGroup($item, $duplicateIdentifier, $result, $collection, $state, $normalizedContentIdentifier);

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        // Resolve remaining pending video companions without paired still images
        $this->resolvePendingVideos(
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
     * Collects all files from the iterator and sorts them so that parent directories
     * appear before subdirectories, with ties broken by pathname.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator Iterator yielding candidate files
     *
     * @return list<SplFileInfo> Sorted file list
     */
    private function collectAndSortFiles(RecursiveIteratorIterator $iterator): array
    {
        /** @var list<SplFileInfo> $files */
        $files = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $files[] = $fileInfo;
        }

        usort($files, static function (SplFileInfo $a, SplFileInfo $b): int {
            $depthA = substr_count($a->getPath(), DIRECTORY_SEPARATOR);
            $depthB = substr_count($b->getPath(), DIRECTORY_SEPARATOR);

            return ($depthA !== $depthB)
                ? $depthA <=> $depthB
                : $a->getPathname() <=> $b->getPathname();
        });

        return $files;
    }

    /**
     * Creates and starts a progress bar for file processing.
     *
     * @param int $max Total number of files to process
     *
     * @return ProgressBar Started progress bar instance
     */
    private function startProgressBar(int $max): ProgressBar
    {
        $progressBar = $this->io->createProgressBar(max($max, 1));
        $progressBar->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar->start();

        return $progressBar;
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
        try {
            $targetFilename = $renameStrategy->generateFilename($sourceFileInfo);

            if ($targetFilename !== null) {
                return TargetFileResult::success(
                    new SplFileInfo(
                        $this->getTargetPathname(
                            $sourceFileInfo,
                            $targetFilename,
                            $context->sourceDirectory,
                        ),
                    ),
                );
            }

            return TargetFileResult::skipped('no capture date');
        } catch (TargetFilenameException $exception) {
            $rootCause = $exception;

            while ($rootCause->getPrevious() instanceof Throwable) {
                $rootCause = $rootCause->getPrevious();
            }

            return TargetFileResult::error($rootCause->getMessage());
        }
    }

    /**
     * Builds the full target pathname from the source file's directory structure
     * and the computed target filename.
     *
     * @param SplFileInfo $sourceFileInfo  Source file providing the directory structure
     * @param string      $targetFilename  Computed target filename (no directory separators)
     * @param string      $sourceDirectory Absolute path to the source directory
     *
     * @return string Full target pathname
     */
    private function getTargetPathname(
        SplFileInfo $sourceFileInfo,
        string $targetFilename,
        string $sourceDirectory,
    ): string {
        if (str_contains($targetFilename, DIRECTORY_SEPARATOR) || str_contains($targetFilename, '/')) {
            throw new RuntimeException(
                sprintf('Target filename "%s" must not contain directory separators', $targetFilename),
            );
        }

        $sourcePath   = $sourceFileInfo->getPath();
        $relativePath = $sourcePath;

        if (str_starts_with($sourcePath, $sourceDirectory)) {
            $relativePath = substr($sourcePath, strlen($sourceDirectory));
        }

        $relativePath = trim($relativePath, DIRECTORY_SEPARATOR);

        $targetPath = rtrim($sourceDirectory, DIRECTORY_SEPARATOR);

        if ($relativePath !== '') {
            $targetPath .= DIRECTORY_SEPARATOR . $relativePath;
        }

        return $targetPath . DIRECTORY_SEPARATOR . $targetFilename;
    }

    /**
     * Resolves the normalized Live Photo content identifier for the given source file.
     *
     * @param RenameStrategyInterface $renameStrategy Strategy that may expose content identifiers
     * @param SplFileInfo             $sourceFileInfo Source file to query
     *
     * @return string|null Lowercased content identifier, or null
     *
     * @throws TargetFilenameException When reading metadata fails
     */
    private function resolveNormalizedContentIdentifier(
        RenameStrategyInterface $renameStrategy,
        SplFileInfo $sourceFileInfo,
    ): ?string {
        if (!$renameStrategy instanceof LivePhotoAwareRenameStrategyInterface) {
            return null;
        }

        return $renameStrategy->getLivePhotoContentIdentifier($sourceFileInfo);
    }

    /**
     * Creates an AssetItem with temporal metadata and content identifier populated.
     * Updates state maps (temporalMetadataMap, contentIdentifierMap) as side effects.
     *
     * @param SplFileInfo             $file     Source file to extract metadata for
     * @param RenameStrategyInterface $strategy Rename strategy that may expose metadata
     * @param CaptureGroupBuildState  $state    Mutable build-time state to update
     *
     * @return AssetItem Item with metadata and content identifier attached
     */
    private function extractAssetCandidate(
        SplFileInfo $file,
        RenameStrategyInterface $strategy,
        CaptureGroupBuildState $state,
    ): AssetItem {
        $temporalMetadata = null;

        if ($strategy instanceof MetadataAwareRenameStrategyInterface) {
            try {
                $temporalMetadata = $strategy->getTemporalMetadata($file);
            } catch (TargetFilenameException) {
                $temporalMetadata = null;
            }

            if ($temporalMetadata instanceof TemporalMetadata) {
                $state->temporalMetadataMap[$file->getPathname()] = $temporalMetadata;
            }
        }

        $normalizedContentIdentifier = null;

        try {
            $normalizedContentIdentifier = $this->resolveNormalizedContentIdentifier($strategy, $file);
        } catch (TargetFilenameException) {
            $normalizedContentIdentifier = null;
        }

        if ($normalizedContentIdentifier !== null) {
            $state->contentIdentifierMap[$file->getPathname()] = $normalizedContentIdentifier;
        }

        $item = new AssetItem($file);

        return $item->withMetadata($temporalMetadata, $normalizedContentIdentifier);
    }

    /**
     * Ensures a content identifier cache entry exists for the given identifier.
     *
     * @param string|null            $normalizedContentIdentifier Normalized content identifier (null is a no-op)
     * @param CaptureGroupBuildState $state                       Mutable build-time state containing the cache
     */
    private function initContentIdCacheEntry(
        ?string $normalizedContentIdentifier,
        CaptureGroupBuildState $state,
    ): void {
        if ($normalizedContentIdentifier === null) {
            return;
        }

        if (array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            return;
        }

        $state->contentIdentifierCache[$normalizedContentIdentifier] = [
            'duplicateIdentifier' => null,
            'pendingFiles'        => [],
            'target'              => null,
        ];
    }

    /**
     * Tracks quality flags for files with unreliable date metadata.
     * Adds fallback-date and ambiguous-timezone flags to the pipeline context.
     *
     * @param SplFileInfo             $file     Source file to check
     * @param RenameStrategyInterface $strategy Rename strategy that may expose quality info
     * @param PipelineContext         $context  Pipeline context to record quality flags
     */
    private function trackQualityFlags(
        SplFileInfo $file,
        RenameStrategyInterface $strategy,
        PipelineContext $context,
    ): void {
        if (!$strategy instanceof MetadataAwareRenameStrategyInterface) {
            return;
        }

        if ($strategy->hasReliableDateTime($file)) {
            return;
        }

        if ($strategy->isFallbackDateTime($file)) {
            $context->addFallbackDateFile($file->getPathname());
        }

        if ($strategy->isAmbiguousTimezone($file)) {
            $context->addAmbiguousTimezoneFile($file->getPathname());
        }
    }

    /**
     * Defers a video companion with a content identifier for Live Photo pairing.
     * Returns true if the file was deferred and the caller should skip to the next file.
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
    private function deferVideoCompanion(
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

        $cachedDuplicateIdentifier = $state->contentIdentifierCache[$normalizedContentIdentifier]['duplicateIdentifier'];

        if (
            is_string($cachedDuplicateIdentifier)
            && $collection->has($cachedDuplicateIdentifier)
        ) {
            // Still image already processed -- add video directly to its group.
            $existingGroup = $collection->get($cachedDuplicateIdentifier);

            if ($existingGroup instanceof AssetGroup) {
                $existingGroup->addItem($item);
            }
        } else {
            // Still image not yet seen -- queue for later resolution.
            $state->contentIdentifierCache[$normalizedContentIdentifier]['pendingFiles'][] = $file;
            $state->contentIdentifierCache[$normalizedContentIdentifier]['target'] ??= $result->getTargetFile();
        }

        return true;
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
            $this->io->error($exception->getMessage());

            return null;
        }

        if ($duplicateIdentifier === false) {
            if (($normalizedContentIdentifier !== null) && array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
                $state->contentIdentifierCache[$normalizedContentIdentifier]['pendingFiles'][] = $file;
                $state->contentIdentifierCache[$normalizedContentIdentifier]['target']         = $targetFileInfo;
            }

            return null;
        }

        return $duplicateIdentifier;
    }

    /**
     * Creates a new capture group or adds the item to an existing group, then
     * resolves any pending files from the content identifier cache.
     *
     * @param AssetItem              $item                        Asset item to attach
     * @param string                 $duplicateIdentifier         Grouping key for the capture group
     * @param TargetFileResult       $result                      Target file result (guaranteed non-skipped)
     * @param AssetGroupCollection   $collection                  Collection of discovered capture groups
     * @param CaptureGroupBuildState $state                       Mutable build-time state
     * @param string|null            $normalizedContentIdentifier Normalized content identifier for cache resolution
     */
    private function attachToGroup(
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

        // Resolve content identifier cache entries
        if (($normalizedContentIdentifier !== null) && array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            $targetFileInfo = $result->getTargetFile();
            assert($targetFileInfo instanceof SplFileInfo);

            $state->contentIdentifierCache[$normalizedContentIdentifier]['duplicateIdentifier'] = $duplicateIdentifier;
            $state->contentIdentifierCache[$normalizedContentIdentifier]['target']              = $targetFileInfo;

            foreach ($state->contentIdentifierCache[$normalizedContentIdentifier]['pendingFiles'] as $pendingFile) {
                $pendingItem = new AssetItem($pendingFile);
                $pendingItem = $pendingItem->withMetadata(
                    $state->temporalMetadataMap[$pendingFile->getPathname()] ?? null,
                    $state->contentIdentifierMap[$pendingFile->getPathname()] ?? null,
                );
                $group->addItem($pendingItem);
            }

            $state->contentIdentifierCache[$normalizedContentIdentifier]['pendingFiles'] = [];
        }
    }

    /**
     * Handles a file whose rename strategy returned a skipped or error result.
     *
     * When a content identifier cache entry exists, the file is either added to an
     * already-resolved capture group or queued as pending. Otherwise, it is recorded
     * as a skipped file with its reason in the pipeline context.
     *
     * @param SplFileInfo            $sourceFileInfo              Source file being processed
     * @param TargetFileResult       $result                      Skipped/error result from the rename strategy
     * @param AssetGroupCollection   $collection                  Collection of discovered capture groups
     * @param PipelineContext        $context                     Pipeline context to record skipped files
     * @param CaptureGroupBuildState $state                       Mutable build-time state
     * @param string|null            $normalizedContentIdentifier Normalized content identifier for cache lookup
     */
    private function handleSkippedFile(
        SplFileInfo $sourceFileInfo,
        TargetFileResult $result,
        AssetGroupCollection $collection,
        PipelineContext $context,
        CaptureGroupBuildState $state,
        ?string $normalizedContentIdentifier,
    ): void {
        if (($normalizedContentIdentifier !== null) && array_key_exists($normalizedContentIdentifier, $state->contentIdentifierCache)) {
            $cachedDuplicateIdentifier = $state->contentIdentifierCache[$normalizedContentIdentifier]['duplicateIdentifier'];

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
            } else {
                $state->contentIdentifierCache[$normalizedContentIdentifier]['pendingFiles'][] = $sourceFileInfo;
            }
        } else {
            $context->addSkippedFile(new SkippedFile(
                $sourceFileInfo,
                $result->getSkipReason() ?? 'no capture date',
                $result->isError(),
            ));
        }
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

        $this->io->newLine();
        $this->io->text('<fg=cyan>Pairing Live Photos</>');

        $fileCount   = $context->getScannedFileCount();
        $progressBar = $this->startProgressBar($fileCount);

        // Build the content identifier resolver callback
        $contentIdentifierResolver = null;

        if ($renameStrategy instanceof LivePhotoAwareRenameStrategyInterface) {
            $contentIdentifierResolver = $renameStrategy->getLivePhotoContentIdentifier(...);
        }

        if ($contentIdentifierResolver === null) {
            $progressBar->finish();
            $this->io->newLine();

            return;
        }

        $pairings = $this->livePhotoPairingService->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $tempCollection,
            contentIdentifierResolver: $contentIdentifierResolver,
            onFileInspected: static function () use ($progressBar): void {
                $progressBar->advance();
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

        $progressBar->finish();
        $this->io->newLine();
    }

    /**
     * Resolves remaining pending video companions that have no paired still image.
     * These deferred videos fall back to their own EXIF date group.
     *
     * @param CaptureGroupBuildState               $state                       Build-time state with content identifier cache
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy Strategy to generate grouping keys
     * @param AssetGroupCollection                 $collection                  Collection to add resolved groups to
     */
    private function resolvePendingVideos(
        CaptureGroupBuildState $state,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        AssetGroupCollection $collection,
    ): void {
        foreach ($state->contentIdentifierCache as $cacheEntry) {
            if ($cacheEntry['pendingFiles'] === []) {
                continue;
            }

            if (!$cacheEntry['target'] instanceof SplFileInfo) {
                continue;
            }

            $targetFileInfo = $cacheEntry['target'];

            try {
                $duplicateIdentifier = $duplicateIdentifierStrategy->generateIdentifier(
                    $cacheEntry['pendingFiles'][0],
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

            foreach ($cacheEntry['pendingFiles'] as $pendingFile) {
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
