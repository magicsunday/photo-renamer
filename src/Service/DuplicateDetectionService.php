<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use Deprecated;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetectorInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

use function assert;
use function count;
use function strtolower;
use function substr_count;
use function usort;

use const DIRECTORY_SEPARATOR;

/**
 * Central orchestrator of the rename pipeline's grouping and suffix-assignment phases.
 *
 * Phase 1 ({@see groupFilesByDuplicateIdentifier}): scans files, applies the rename strategy
 * to compute target filenames, groups them by duplicate identifier, and builds the content
 * identifier map needed for Live Photo companion detection.
 *
 * Phase 2 ({@see createDuplicateFilenames}): walks each group, selects the canonical file,
 * detects Live Photo companions, applies hash sub-grouping for naming collisions, and assigns
 * sequential "-duplicate-NNN" suffixes to remaining files. Maintains an in-memory disk index
 * to avoid stat() calls when checking target path availability.
 *
 * MIGRATION STATUS (Phase 4 complete):
 * - rename:exif: FULLY MIGRATED to AssetGroup pipeline + ExecutionPlan runtime
 * - rename:hash: uses legacy pipeline (no migration planned — simple command)
 * - rename:pattern: uses legacy pipeline (no migration planned — simple command)
 * - rename:date-pattern: uses legacy pipeline (no migration planned — simple command)
 * - rename:lower: uses legacy pipeline (no migration planned — simple command)
 *
 * Legacy execution path (DuplicateDetectionService → FileDuplicateCollection → renameFiles)
 * is intentionally retained for the above commands. This is End State B per the
 * Phase 4 plan: bounded legacy exceptions with documented rationale.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class DuplicateDetectionService implements DuplicateDetectionServiceInterface
{
    /**
     * Absolute path to the directory being scanned for source files.
     * Set at the start of each public pipeline method.
     */
    private string $sourceDirectory = '';

    /**
     * When true, duplicate targets preserve the source file's original extension
     * instead of inheriting the canonical target's extension.
     */
    private bool $useFileExtensionFromSource = false;

    /**
     * Number of groups where content-hash sub-grouping was applied because
     * multiple distinct file contents shared the same target basename.
     */
    private int $namingCollisions = 0;

    /**
     * In-memory index of all file paths discovered during scanning.
     * Used instead of stat() calls to check whether a target path is occupied.
     *
     * @var array<string, true>
     */
    private array $diskIndex = [];

    /**
     * All scanned files keyed by pathname so post-group heuristics can reason
     * across the whole batch without rescanning the filesystem.
     *
     * @var array<string, SplFileInfo>
     */
    private array $filesByPath = [];

    /**
     * Map from source pathname to normalized Live Photo content identifier.
     * Built during grouping, used for companion detection in createDuplicateFilenames.
     *
     * @var array<string, string>
     */
    private array $contentIdentifierMap = [];

    /**
     * Temporal metadata captured during grouping for later conflict detection.
     *
     * @var array<string, TemporalMetadata>
     */
    private array $temporalMetadataMap = [];

    /**
     * Number of files scanned during the last grouping pass.
     */
    private int $lastScannedFileCount = 0;

    /**
     * Files skipped during the last grouping pass because the rename strategy
     * could not produce a target filename (no capture date or metadata read error).
     *
     * @var list<SkippedFile>
     */
    private array $skippedFiles = [];

    /**
     * Pathnames of files whose capture date was derived from the fallback
     * DateTime tag (0x0132) instead of DateTimeOriginal or CreateDate.
     *
     * @var array<string, true>
     */
    private array $fallbackDateFiles = [];

    /**
     * Pathnames of files with ambiguous timezone (cannot determine UTC vs local).
     *
     * @var array<string, true>
     */
    private array $ambiguousTimezoneFiles = [];

    /**
     * Pathnames of files that look like a Live Photo pair by fallback heuristics
     * but expose conflicting non-null content identifiers.
     *
     * @var array<string, true>
     */
    private array $livePhotoConflictFiles = [];

    /**
     * @param SymfonyStyle                             $io                                 Symfony Style IO for progress indicators and error output.
     * @param HashSubGroupingServiceInterface          $hashSubGroupingService             Service for content-based sub-grouping (deduplication).
     * @param MediaTypeClassifierInterface             $mediaTypeClassifier                Classifier for media types (Photo vs. Video).
     * @param LivePhotoConflictDetectorInterface|null  $livePhotoConflictDetector          Detector for ID conflicts in Live Photos.
     * @param DuplicateCanonicalRenameSelector         $duplicateCanonicalRenameSelector   Selector for canonical rename choice and promotion flags.
     * @param DuplicateSuffixAssigner                  $duplicateSuffixAssigner            Assigns unique `-duplicate-NNN` targets in the legacy flow.
     * @param LegacyLivePhotoCompanionDetector|null    $livePhotoCompanionDetector         Detects companion renames inside legacy Live Photo groups.
     * @param LegacyLivePhotoTargetPromoter|null       $livePhotoTargetPromoter            Promotes live-photo canonicals from video targets to still targets.
     * @param LegacyLivePhotoQualityFlagPropagator     $livePhotoQualityFlagPropagator     Propagates still-side quality flags to paired companion videos.
     * @param LegacyLivePhotoDuplicateCoordinator|null $livePhotoDuplicateCoordinator      Coordinates companion detection and pair recording for legacy Live Photo groups.
     * @param LegacyTargetPathResolver                 $targetPathResolver                 Resolves absolute target pathnames while preserving legacy directory structure.
     * @param LegacyTargetFileResolver                 $targetFileResolver                 Resolves target-file results from generated filenames and strategy failures.
     * @param LegacyTargetOccupancyChecker             $targetOccupancyChecker             Checks whether a target pathname is blocked by an external file.
     * @param LegacyDuplicateTargetCandidateFactory    $duplicateTargetCandidateFactory    Builds concrete `-duplicate-NNN` target candidates in the legacy directory layout.
     * @param LegacyContentIdentifierCoordinator|null  $legacyContentIdentifierCoordinator Coordinates pending/skipped Live Photo content-ID handling in the legacy grouping pass.
     */
    private readonly LegacyLivePhotoTargetPromoter $livePhotoTargetPromoter;

    private readonly LegacyLivePhotoDuplicateCoordinator $livePhotoDuplicateCoordinator;

    private readonly LegacyDuplicateTargetCandidateFactory $duplicateTargetCandidateFactory;

    private readonly LegacyContentIdentifierCoordinator $legacyContentIdentifierCoordinator;

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly HashSubGroupingServiceInterface $hashSubGroupingService,
        private readonly MediaTypeClassifierInterface $mediaTypeClassifier,
        private readonly ?LivePhotoConflictDetectorInterface $livePhotoConflictDetector = null,
        private readonly DuplicateCanonicalRenameSelector $duplicateCanonicalRenameSelector = new DuplicateCanonicalRenameSelector(),
        private readonly DuplicateSuffixAssigner $duplicateSuffixAssigner = new DuplicateSuffixAssigner(),
        ?LegacyLivePhotoCompanionDetector $livePhotoCompanionDetector = null,
        ?LegacyLivePhotoTargetPromoter $livePhotoTargetPromoter = null,
        private readonly LegacyLivePhotoQualityFlagPropagator $livePhotoQualityFlagPropagator = new LegacyLivePhotoQualityFlagPropagator(),
        ?LegacyLivePhotoDuplicateCoordinator $livePhotoDuplicateCoordinator = null,
        private readonly LegacyTargetPathResolver $targetPathResolver = new LegacyTargetPathResolver(),
        private readonly ?LegacyTargetFileResolver $targetFileResolver = new LegacyTargetFileResolver(),
        private readonly LegacyTargetOccupancyChecker $targetOccupancyChecker = new LegacyTargetOccupancyChecker(),
        ?LegacyDuplicateTargetCandidateFactory $duplicateTargetCandidateFactory = null,
        ?LegacyContentIdentifierCoordinator $legacyContentIdentifierCoordinator = null,
    ) {
        $livePhotoCompanionDetector ??= new LegacyLivePhotoCompanionDetector($this->mediaTypeClassifier);
        $this->livePhotoTargetPromoter = $livePhotoTargetPromoter
            ?? new LegacyLivePhotoTargetPromoter($this->mediaTypeClassifier);
        $this->livePhotoDuplicateCoordinator = $livePhotoDuplicateCoordinator
            ?? new LegacyLivePhotoDuplicateCoordinator(
                $livePhotoCompanionDetector,
                $this->mediaTypeClassifier,
            );
        $this->duplicateTargetCandidateFactory = $duplicateTargetCandidateFactory
            ?? new LegacyDuplicateTargetCandidateFactory($this->targetPathResolver);
        $this->legacyContentIdentifierCoordinator = $legacyContentIdentifierCoordinator
            ?? new LegacyContentIdentifierCoordinator($this->mediaTypeClassifier);
    }

    /**
     * Returns the number of groups where content-hash sub-grouping was applied.
     */
    #[Override]
    public function getNamingCollisions(): int
    {
        return $this->namingCollisions;
    }

    /**
     * Returns the number of files scanned during the last call to
     * {@see groupFilesByDuplicateIdentifier()}.
     */
    #[Override]
    public function getLastScannedFileCount(): int
    {
        return $this->lastScannedFileCount;
    }

    /**
     * Returns files skipped during the last grouping pass because the rename
     * strategy could not produce a target filename.
     *
     * @return list<SkippedFile>
     */
    #[Override]
    public function getSkippedFiles(): array
    {
        return $this->skippedFiles;
    }

    /**
     * Returns pathnames of files whose capture date was derived from the
     * fallback DateTime tag (0x0132) instead of DateTimeOriginal or CreateDate.
     *
     * @return array<string, true>
     */
    #[Override]
    public function getFallbackDateFiles(): array
    {
        return $this->fallbackDateFiles;
    }

    /**
     * @return array<string, true>
     */
    #[Override]
    public function getAmbiguousTimezoneFiles(): array
    {
        return $this->ambiguousTimezoneFiles;
    }

    /**
     * @return array<string, true>
     */
    #[Override]
    public function getLivePhotoConflictFiles(): array
    {
        return $this->livePhotoConflictFiles;
    }

    /**
     * Releases all cached hash results to free memory after the pipeline completes.
     */
    #[Override]
    public function clearHashCache(): void
    {
        $this->hashSubGroupingService->clearCache();
    }

    /**
     * Groups files based on a unique identifier (e.g., capture date).
     *
     * This grouping is the foundation for duplicate detection. Files that
     * generate the same grouping key (duplicate identifier) are collected
     * into a {@see FileDuplicate} group.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner>    $iterator                    Iterator over the source files.
     * @param RenameStrategyInterface              $renameStrategy              Strategy for generating target filenames.
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy Strategy for determining the grouping keys.
     * @param string                               $sourceDirectory             Absolute path to the source directory.
     *
     * @return FileDuplicateCollection Collection of identified duplicate groups.
     */
    #[Override]
    #[Deprecated(message: <<<'TXT'
    Use CaptureGroupBuilder::build() for the AssetGroup pipeline.
                 Retained for commands other than rename:exif.
    TXT)]
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        string $sourceDirectory,
    ): FileDuplicateCollection {
        $this->sourceDirectory = $sourceDirectory;

        $files                      = $this->collectAndSortFiles($iterator);
        $this->lastScannedFileCount = count($files);

        $this->buildDiskIndex($files);

        $progressBar = $this->startProgressBar(count($files));

        $fileDuplicateCollection = new FileDuplicateCollection();
        /**
         * @var array<string, ContentIdentifierCacheEntry> Map for coordinating Live Photo still/video pairing during the first pass.
         */
        $contentIdentifierCache = [];

        foreach ($files as $sourceFileInfo) {
            // The resulting file object
            $result = $this->getTargetFileInfo(
                $sourceFileInfo,
                $renameStrategy
            );

            if ($renameStrategy instanceof MetadataAwareRenameStrategyInterface) {
                try {
                    $temporalMetadata = $renameStrategy->getTemporalMetadata($sourceFileInfo);
                } catch (TargetFilenameException) {
                    $temporalMetadata = null;
                }

                if ($temporalMetadata instanceof TemporalMetadata) {
                    $this->temporalMetadataMap[$sourceFileInfo->getPathname()] = $temporalMetadata;
                }
            }

            try {
                $normalizedContentIdentifier = $this->resolveNormalizedContentIdentifier(
                    $renameStrategy,
                    $sourceFileInfo,
                );
            } catch (TargetFilenameException) {
                $normalizedContentIdentifier = null;
            }

            // Store content identifier for companion detection in createDuplicateFilenames.
            if ($normalizedContentIdentifier !== null) {
                $this->contentIdentifierMap[$sourceFileInfo->getPathname()] = $normalizedContentIdentifier;
            }

            $contentIdentifierCacheEntry = $this->legacyContentIdentifierCoordinator->resolveCacheEntry(
                $normalizedContentIdentifier,
                $contentIdentifierCache,
            );

            if ($result->isSkipped()) {
                $this->legacyContentIdentifierCoordinator->handleSkippedFile(
                    $sourceFileInfo,
                    $result,
                    $fileDuplicateCollection,
                    $contentIdentifierCacheEntry,
                    $this->skippedFiles,
                );

                $progressBar->advance();

                continue;
            }

            // Track files with unreliable dates (fallback or ambiguous timezone)
            // but only if the raw metadata does NOT already match the filename
            // (which means the file was already fixed by write-date).
            if ($renameStrategy instanceof MetadataAwareRenameStrategyInterface) {
                $qualityFlags = MetadataQualityFlagResolver::resolve($sourceFileInfo, $renameStrategy);

                if ($qualityFlags['hasFallbackDate']) {
                    $this->fallbackDateFiles[$sourceFileInfo->getPathname()] = true;
                }

                if ($qualityFlags['hasAmbiguousTimezone']) {
                    $this->ambiguousTimezoneFiles[$sourceFileInfo->getPathname()] = true;
                }
            }

            // Video companions with content identifiers defer to Live Photo pairing
            // instead of being grouped by their own EXIF date. This ensures they
            // receive the paired still image's timestamp, not their own.
            if (
                ($contentIdentifierCacheEntry instanceof ContentIdentifierCacheEntry)
                && $this->legacyContentIdentifierCoordinator->handleDeferredVideoCompanion(
                    $sourceFileInfo,
                    $result,
                    $fileDuplicateCollection,
                    $contentIdentifierCacheEntry,
                )
            ) {
                $progressBar->advance();

                continue;
            }

            // Guaranteed non-null after isSkipped() guard above
            $targetFileInfo = $result->getTargetFile();
            assert($targetFileInfo instanceof SplFileInfo);

            try {
                $duplicateIdentifier = $duplicateIdentifierStrategy->generateIdentifier(
                    $sourceFileInfo,
                    $targetFileInfo
                );
            } catch (HashComputationException $exception) {
                $this->io->error($exception->getMessage());

                $progressBar->advance();

                continue;
            }

            if ($duplicateIdentifier === false) {
                $this->legacyContentIdentifierCoordinator->queuePendingFileForUnresolvedIdentifier(
                    $sourceFileInfo,
                    $targetFileInfo,
                    $contentIdentifierCacheEntry,
                );

                $progressBar->advance();

                continue;
            }

            // Create duplicate object storing relevant data
            $fileDuplicate = new FileDuplicate();
            $fileDuplicate
                ->addFile($sourceFileInfo)
                ->setTarget($targetFileInfo);

            if ($fileDuplicateCollection->has($duplicateIdentifier)) {
                /** @var FileDuplicate $fileDuplicate */
                $fileDuplicate = $fileDuplicateCollection->get($duplicateIdentifier);

                $this->promoteLivePhotoTargetIfNecessary(
                    $duplicateIdentifier,
                    $fileDuplicate,
                    $targetFileInfo,
                );

                $fileDuplicate->addFile($sourceFileInfo);
            } else {
                $fileDuplicateCollection->set($duplicateIdentifier, $fileDuplicate);
            }

            $this->legacyContentIdentifierCoordinator->attachToResolvedGroup(
                $duplicateIdentifier,
                $fileDuplicate,
                $targetFileInfo,
                $contentIdentifierCacheEntry,
            );

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        // Resolve remaining pending video companions that have no paired still image.
        // These deferred videos fall back to their own EXIF date group.
        $this->legacyContentIdentifierCoordinator->resolvePendingFallbackGroups(
            $contentIdentifierCache,
            $fileDuplicateCollection,
            function (SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo) use ($duplicateIdentifierStrategy): ?string {
                try {
                    $duplicateIdentifier = $duplicateIdentifierStrategy->generateIdentifier(
                        $sourceFileInfo,
                        $targetFileInfo,
                    );
                } catch (HashComputationException) {
                    return null;
                }

                return ($duplicateIdentifier === false) ? null : $duplicateIdentifier;
            },
        );

        if ($this->livePhotoConflictDetector instanceof LivePhotoConflictDetectorInterface) {
            $this->livePhotoConflictFiles = [
                ...$this->livePhotoConflictFiles,
                ...$this->livePhotoConflictDetector->detectConflictFiles(
                    $this->filesByPath,
                    $this->temporalMetadataMap,
                ),
            ];
        }

        return $fileDuplicateCollection;
    }

    /**
     * Generates unique target filenames for all files in the collection.
     *
     * This method assigns a final path to each file within a group. It ensures that:
     * 1. Canonical files (the "main file" of a group) keep or receive their target name.
     * 2. Real duplicates receive a sequential suffix (e.g., -duplicate-001).
     * 3. Live Photo partners (video + photo) share the same base path.
     * 4. Files with identical content (same hash) are grouped into subgroups.
     *
     * NOTE: {@see groupFilesByDuplicateIdentifier()} must have been called beforehand.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection    The collection to process.
     * @param string                  $sourceDirectory            Base directory for relative paths.
     * @param bool                    $useFileExtensionFromSource Whether to keep the original file extension.
     * @param bool                    $skipHashSubGrouping        Whether to skip content-based sub-grouping.
     *
     * @return FileDuplicateCollection Updated collection with assigned renames.
     */
    #[Override]
    #[Deprecated(message: <<<'TXT'
    Use RoleAssigner::assign() + TargetNameResolver::resolve() + CollisionResolver::resolve()
                 for the AssetGroup pipeline. Retained for commands other than rename:exif.
    TXT)]
    public function createDuplicateFilenames(
        FileDuplicateCollection $fileDuplicateCollection,
        string $sourceDirectory,
        bool $useFileExtensionFromSource = false,
        bool $skipHashSubGrouping = false,
    ): FileDuplicateCollection {
        $this->sourceDirectory            = $sourceDirectory;
        $this->useFileExtensionFromSource = $useFileExtensionFromSource;
        $this->namingCollisions           = 0;

        // Ensure disk index is populated when called without prior groupFilesByDuplicateIdentifier.
        if ($this->diskIndex === []) {
            foreach ($fileDuplicateCollection as $fileDuplicate) {
                foreach ($fileDuplicate->getFiles() as $fileInfo) {
                    $this->diskIndex[$fileInfo->getPathname()] = true;
                }
            }
        }

        // Collect Live Photo pairs (still → companion video) for quality flag propagation.
        /** @var list<LegacyLivePhotoPair> $livePhotoPairs */
        $livePhotoPairs = [];

        $progressBar = $this->startProgressBar($fileDuplicateCollection->count());

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getFiles() as $renameSourceFileInfo) {
                $renameTargetFileExtension = $fileDuplicate->getTarget()->getExtension();

                // Modify the target file extension if the file extension from the source should be used.
                // This allows us to rename different file types but with the same name.
                if ($this->useFileExtensionFromSource) {
                    $renameTargetFileExtension = FileHelper::normalizeExtension(
                        $renameSourceFileInfo->getExtension()
                    );
                }

                $targetPathname = $this->getTargetPathname(
                    $renameSourceFileInfo,
                    FileHelper::basenameWithoutExtension($fileDuplicate->getTarget())
                    . '.' . $renameTargetFileExtension
                );

                $renameTargetFileInfo = new SplFileInfo($targetPathname);

                $fileDuplicate->addRename(
                    new Rename(
                        $renameSourceFileInfo,
                        $renameTargetFileInfo
                    )
                );
            }

            $renames = $fileDuplicate->getRenames();

            $canonicalTargetPath = $fileDuplicate->getTarget()->getPathname();

            $canonicalSelection = $this->duplicateCanonicalRenameSelector->select(
                $fileDuplicate,
                $this->contentIdentifierMap,
            );
            $canonicalRename         = $canonicalSelection->canonicalRename;
            $canonicalNeedsPromotion = $canonicalSelection->canonicalNeedsPromotion;

            $renames->reindex();
            $fileDuplicate->setRenames($renames);

            // Detect Live Photo companion: in a live-photo group, exactly one file
            // of a different media type (e.g. MOV for a JPG canonical) should receive
            // the same base name without a duplicate suffix.
            $livePhotoCoordination = $this->coordinateLivePhotoDuplicateGroup(
                $canonicalRename,
                $fileDuplicate,
            );
            $companionRename = $livePhotoCoordination->companionRename;

            if ($livePhotoCoordination->livePhotoPair instanceof LegacyLivePhotoPair) {
                $livePhotoPairs[] = $livePhotoCoordination->livePhotoPair;
            }

            // Content-hash sub-grouping with multi-signal perceptual merge: when
            // multiple distinct files share the same target name, assign sequential
            // numbers per unique content hash. Hash groups that score ≥95 on the
            // combined similarity metric (dHash + wHash + HF + color + duration)
            // are merged as semantic duplicates.
            $subGroupApplied = !$skipHashSubGrouping
                && ($this->hashSubGroupingService->apply(
                    $fileDuplicate,
                    $canonicalRename,
                    $companionRename,
                    $this->contentIdentifierMap,
                    $this->getTargetPathname(...),
                    $this->temporalMetadataMap,
                ) !== null);

            // Release per-group Imagick instances cached during perceptual scoring
            $this->hashSubGroupingService->clearCache();

            if ($subGroupApplied) {
                ++$this->namingCollisions;
                $progressBar->advance();

                continue;
            }

            // Per-extension duplicate counters — each extension gets independent sequential numbering
            // (e.g., -duplicate-001.jpg and -duplicate-001.mov can coexist in the same group).
            /** @var array<string, int> $duplicateCountByExtension */
            $duplicateCountByExtension = [];
            $duplicateEntries          = 0;

            // Collect source paths of all files in this group so that on-disk collisions
            // with group members (who will be moved) are treated as available, and count
            // non-canonical/non-companion entries in a single pass.
            $groupSourcePaths = [];

            foreach ($fileDuplicate->getRenames() as $rename) {
                $groupSourcePaths[$rename->getSource()->getPathname()] = true;

                if (($canonicalRename instanceof Rename) && ($rename === $canonicalRename)) {
                    continue;
                }

                if (($companionRename instanceof Rename) && ($rename === $companionRename)) {
                    continue;
                }

                ++$duplicateEntries;
            }

            $hasAdditionalRenames = $duplicateEntries > 0;
            $processedDuplicates  = 0;

            // Assign unique target filenames to remaining renames.

            foreach ($fileDuplicate->getRenames() as $rename) {
                $isCanonicalRename = ($canonicalRename instanceof Rename)
                    && ($rename === $canonicalRename)
                    && $canonicalNeedsPromotion;
                $isCompanionRename = ($companionRename instanceof Rename) && ($rename === $companionRename);

                // Live Photo companions are treated like canonicals: same base name, no suffix.
                if ($isCompanionRename) {
                    ++$processedDuplicates;

                    continue;
                }

                $ext = strtolower($rename->getTarget()->getExtension());

                if (!isset($duplicateCountByExtension[$ext])) {
                    $duplicateCountByExtension[$ext] = 1;
                }

                if ($isCanonicalRename) {
                    $rename->setTarget(
                        $this->resolveCanonicalTarget(
                            $rename->getSource(),
                            $rename->getTarget(),
                            $duplicateCountByExtension[$ext],
                            $groupSourcePaths,
                        )
                    );
                } else {
                    // Non-canonical files sharing the canonical target path need a suffix.
                    // When the canonical itself doesn't need promotion (base name already
                    // occupied by another extension), it also needs disambiguation.
                    $requiresCanonicalDisambiguation = ($canonicalRename instanceof Rename)
                        && (($rename !== $canonicalRename) || !$canonicalNeedsPromotion)
                        && ($rename->getTarget()->getPathname() === $canonicalTargetPath);

                    // Cross-directory duplicate: file is in a different directory than
                    // the canonical. Even if source == target (idempotent in its own dir),
                    // it must get a duplicate suffix because the canonical lives elsewhere.
                    $isCrossDirectoryDuplicate = ($canonicalRename instanceof Rename)
                        && ($rename->getSource()->getPath() !== $canonicalRename->getSource()->getPath());

                    $rename->setTarget(
                        $this->createDuplicateTargetFileInfo(
                            $rename->getSource(),
                            $rename->getTarget(),
                            $duplicateCountByExtension[$ext],
                            $processedDuplicates === 0,
                            $hasAdditionalRenames,
                            $requiresCanonicalDisambiguation || $isCrossDirectoryDuplicate,
                            $groupSourcePaths,
                        )
                    );
                }

                // Register the assigned target in the disk index so subsequent
                // groups see it as occupied without needing a stat() call.
                $this->diskIndex[$rename->getTarget()->getPathname()] = true;

                ++$processedDuplicates;
            }

            if (($canonicalRename instanceof Rename) && $canonicalNeedsPromotion) {
                $fileDuplicate->setTarget($canonicalRename->getTarget());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        // LP atomicity pass: propagate the still image's quality flags to its
        // companion video so the pair is always tagged consistently.
        $this->propagateLivePhotoQualityFlags($livePhotoPairs);

        return $fileDuplicateCollection;
    }

    /**
     * Propagates quality flags (ambiguous timezone, fallback date) from the Live
     * Photo still image to its companion video. This ensures atomic tagging: both
     * files in the pair receive the same [W] or [F] flag.
     *
     * @param list<LegacyLivePhotoPair> $livePhotoPairs
     */
    private function propagateLivePhotoQualityFlags(array $livePhotoPairs): void
    {
        $this->livePhotoQualityFlagPropagator->propagate(
            $livePhotoPairs,
            $this->ambiguousTimezoneFiles,
            $this->fallbackDateFiles,
        );
    }

    /**
     * Creates and starts a Symfony progress bar tailored to the current workload.
     *
     * @param int $max maximum number of steps the progress bar should represent
     *
     * @return ProgressBar configured progress bar instance ready for updates
     */
    private function startProgressBar(int $max): ProgressBar
    {
        $progressBar = $this->io->createProgressBar(max($max, 1));
        $progressBar->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar->start();

        return $progressBar;
    }

    /**
     * Collects all files from the iterator and sorts them so that parent directories
     * appear before subdirectories, with ties broken by pathname.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator iterator yielding candidate files
     *
     * @return list<SplFileInfo> sorted file list
     */
    private function collectAndSortFiles(RecursiveIteratorIterator $iterator): array
    {
        /** @var list<SplFileInfo> $files */
        $files = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $files[] = $fileInfo;
        }

        usort($files, static function (SplFileInfo $fileA, SplFileInfo $fileB): int {
            $depthA = substr_count($fileA->getPath(), DIRECTORY_SEPARATOR);
            $depthB = substr_count($fileB->getPath(), DIRECTORY_SEPARATOR);

            return ($depthA !== $depthB)
                ? $depthA <=> $depthB
                : $fileA->getPathname() <=> $fileB->getPathname();
        });

        return $files;
    }

    /**
     * Resets per-run state and populates the in-memory disk index from the given files.
     *
     * @param list<SplFileInfo> $files files discovered by the iterator
     */
    private function buildDiskIndex(array $files): void
    {
        $this->diskIndex              = [];
        $this->filesByPath            = [];
        $this->contentIdentifierMap   = [];
        $this->temporalMetadataMap    = [];
        $this->skippedFiles           = [];
        $this->fallbackDateFiles      = [];
        $this->ambiguousTimezoneFiles = [];
        $this->livePhotoConflictFiles = [];

        foreach ($files as $file) {
            $this->diskIndex[$file->getPathname()]   = true;
            $this->filesByPath[$file->getPathname()] = $file;
        }
    }

    /**
     * Resolves the target for the canonical file in a duplicate group.
     *
     * The canonical file keeps the unsuffixed base name when possible.
     * When the target is already occupied by a file outside the group,
     * a unique duplicate suffix is assigned instead.
     *
     * @param SplFileInfo         $source           source file currently being processed
     * @param SplFileInfo         $target           initial target file information
     * @param int                 $duplicateCount   counter used to create unique duplicate suffixes (passed by reference)
     * @param array<string, true> $groupSourcePaths source paths of all files in the current group
     *
     * @return SplFileInfo file information pointing to the deduplicated target
     */
    private function resolveCanonicalTarget(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        array $groupSourcePaths,
    ): SplFileInfo {
        return $this->duplicateSuffixAssigner->resolveCanonicalTarget(
            $source,
            $target,
            $duplicateCount,
            $groupSourcePaths,
            $this->isTargetOccupied(...),
            $this->getNewDuplicateTargetFileInfo(...),
        );
    }

    /**
     * Resolves the target file information for a duplicate, ensuring unique filenames.
     *
     * @param SplFileInfo         $source                          source file currently being processed
     * @param SplFileInfo         $target                          initial target file information
     * @param int                 $duplicateCount                  counter used to create unique duplicate suffixes (passed by reference)
     * @param bool                $isFirst                         whether the file is the first item within the duplicate group
     * @param bool                $hasAdditionalRenames            whether the group has more than one non-canonical rename
     * @param bool                $requiresCanonicalDisambiguation whether the file shares the canonical target path
     * @param array<string, true> $groupSourcePaths                source paths of all files in the current group
     *
     * @return SplFileInfo file information pointing to the deduplicated target
     */
    private function createDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        bool $isFirst = false,
        bool $hasAdditionalRenames = false,
        bool $requiresCanonicalDisambiguation = false,
        array $groupSourcePaths = [],
    ): SplFileInfo {
        return $this->duplicateSuffixAssigner->createDuplicateTargetFileInfo(
            $source,
            $target,
            $duplicateCount,
            $isFirst,
            $hasAdditionalRenames,
            $requiresCanonicalDisambiguation,
            $groupSourcePaths,
            $this->isTargetOccupied(...),
            $this->getNewDuplicateTargetFileInfo(...),
        );
    }

    /**
     * Returns a new file info object with a unique filename.
     *
     * @param SplFileInfo $source         source file currently being processed
     * @param SplFileInfo $target         initial target file information
     * @param string      $targetBasename base filename (without extension) used for duplicate naming
     * @param int         $duplicateCount counter used to create unique duplicate suffixes
     *
     * @return SplFileInfo file info representing the next duplicate candidate
     */
    private function getNewDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int $duplicateCount,
    ): SplFileInfo {
        return $this->duplicateTargetCandidateFactory->create(
            $this->sourceDirectory,
            $source,
            $target,
            $targetBasename,
            $duplicateCount,
        );
    }

    /**
     * Builds the target pathname for a source file and generated filename.
     *
     * @param SplFileInfo $sourceFileInfo source file for which the target path should be computed
     * @param string      $targetFilename filename (without directory) to use in the destination
     *
     * @return string absolute pathname pointing to the intended target location
     */
    private function getTargetPathname(SplFileInfo $sourceFileInfo, string $targetFilename): string
    {
        return $this->targetPathResolver->resolve(
            $this->sourceDirectory,
            $sourceFileInfo,
            $targetFilename,
        );
    }

    /**
     * Returns a new target file object for the given source file object.
     *
     * @param SplFileInfo             $sourceFileInfo source file that should be renamed
     * @param RenameStrategyInterface $renameStrategy strategy responsible for generating the target filename
     */
    private function getTargetFileInfo(SplFileInfo $sourceFileInfo, RenameStrategyInterface $renameStrategy): TargetFileResult
    {
        $targetFileResolver = $this->targetFileResolver;
        assert($targetFileResolver instanceof LegacyTargetFileResolver);

        return $targetFileResolver->resolve(
            $this->sourceDirectory,
            $sourceFileInfo,
            $renameStrategy,
        );
    }

    /**
     * Promotes the group's canonical target to a still image when a Live Photo
     * group's current canonical is a video (MOV) and the candidate is a still (HEIC/JPG).
     * Ensures the still image always takes precedence as the group's base name source.
     *
     * @param string        $duplicateIdentifier Group key (only live-photo: prefixed groups are affected)
     * @param FileDuplicate $fileDuplicate       The duplicate group whose target may be promoted
     * @param SplFileInfo   $candidateTarget     Newly encountered target to consider for promotion
     */
    private function promoteLivePhotoTargetIfNecessary(
        string $duplicateIdentifier,
        FileDuplicate $fileDuplicate,
        SplFileInfo $candidateTarget,
    ): void {
        $this->livePhotoTargetPromoter->promote(
            $duplicateIdentifier,
            $fileDuplicate,
            $candidateTarget,
        );
    }

    /**
     * Coordinates companion detection and normalized pair tracking for one legacy Live Photo group.
     *
     * @param Rename|null   $canonicalRename Canonical rename chosen for the duplicate group.
     * @param FileDuplicate $fileDuplicate   Duplicate group currently being processed.
     *
     * @return LegacyLivePhotoDuplicateCoordination Companion rename and optional still/companion pair for later flag propagation.
     */
    private function coordinateLivePhotoDuplicateGroup(
        ?Rename $canonicalRename,
        FileDuplicate $fileDuplicate,
    ): LegacyLivePhotoDuplicateCoordination {
        return $this->livePhotoDuplicateCoordinator->coordinate(
            $canonicalRename,
            $fileDuplicate,
            $this->contentIdentifierMap,
            $this->livePhotoConflictFiles,
        );
    }

    /**
     * Checks whether the target path is already occupied by a file that is NOT the
     * source itself and NOT another member of the same group (who will be moved away).
     * Uses the in-memory disk index for fast lookups, falling back to stat() for paths
     * outside the scanned directories.
     *
     * @param SplFileInfo         $target           Target path to check
     * @param SplFileInfo         $source           Source file being processed (never considered occupied)
     * @param array<string, true> $groupSourcePaths Source paths of all files in the current group
     */
    private function isTargetOccupied(SplFileInfo $target, SplFileInfo $source, array $groupSourcePaths): bool
    {
        return $this->targetOccupancyChecker->isOccupied(
            $target,
            $source,
            $groupSourcePaths,
            $this->diskIndex,
        );
    }

    /**
     * Extracts the Live Photo content identifier from the rename strategy,
     * returning null when the strategy does not support Live Photo awareness or when the
     * file has no content identifier.
     *
     * The value returned by {@see LivePhotoAwareRenameStrategyInterface::getLivePhotoContentIdentifier()}
     * is already normalized (lowercased, trimmed) by the provider, so no further
     * normalization is applied here.
     *
     * @param RenameStrategyInterface $renameStrategy Strategy that may implement LivePhotoAwareRenameStrategyInterface
     * @param SplFileInfo             $sourceFileInfo File to extract the content identifier from
     *
     * @return string|null Already-normalized content identifier, or null
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
}
