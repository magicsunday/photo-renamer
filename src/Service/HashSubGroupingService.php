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
use Imagick;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDiffResult;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use Override;
use SplFileInfo;

use function array_key_exists;
use function array_keys;
use function basename;
use function count;
use function microtime;
use function min;
use function spl_object_id;
use function sprintf;
use function strtolower;

/**
 * Applies content-hash based sub-grouping to a duplicate group.
 *
 * When multiple files share the same target name but have distinct content,
 * this service assigns sequential sub-group numbers per unique content hash.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class HashSubGroupingService implements HashSubGroupingServiceInterface
{
    /**
     * RMSE threshold for dHash==0 pairs (identical gradient structure).
     * Only codec noise can produce dHash==0 with non-zero RMSE — format
     * conversions (HEIC→JPG) reach up to 0.035, so 0.04 covers them.
     */
    private const float SAFE_MERGE_RMSE_EXACT = 0.04;

    /**
     * RMSE threshold for simple two-file dHash==0 format backups.
     *
     * This wider window is intentionally not used for Live Photo/edit groups,
     * where an edited asset can also retain identical dHash structure.
     */
    private const float SAFE_MERGE_RMSE_EXACT_FORMAT_BACKUP = 0.06;

    /**
     * RMSE threshold for dHash 1–2 (near-identical gradient structure).
     * Codec noise can flip 1–2 gradient bits in the 9×8 dHash grid due to
     * quantization differences between HEIC and JPG encoders.
     * Measured: HEIC→JPG with dHash=1 at RMSE 0.026, minimal edits at 0.037.
     */
    private const float SAFE_MERGE_RMSE_NEAR = 0.03;

    /**
     * RMSE threshold for dHash>=3 pairs (gradient structure clearly differs).
     * Multiple gradient flips indicate a real image change. Strictest threshold.
     */
    private const float SAFE_MERGE_RMSE_CHANGED = 0.025;

    /**
     * Maximum chroma energy difference for merging. Detects color→grayscale
     * conversions: codec conversions preserve chroma (~0.013), desaturation
     * produces a large gap (0.04+). Threshold sits between the two ranges.
     * Acts as a merge veto independent of RMSE.
     */
    private const float MAX_CHROMA_DIFFERENCE = 0.03;

    /**
     * Maximum RMSE for merging isDuplicateLikely pairs.
     * Configurable at runtime via setMaxMergeRmse().
     * HEIC↔JPG format conversions: 0.001–0.013. Different photos: 0.25+.
     */
    private float $maxMergeRmse = 0.06;

    /**
     * @param SafeHashCalculatorInterface       $hashCalculator           Computes file content hashes for sub-group keying
     * @param ProgressReporterInterface         $progressReporter         Narrow reporting boundary for recoverable diagnostics
     * @param MediaTypeClassifierInterface      $mediaTypeClassifier      Classifies files as still or video
     * @param PerceptualHashCalculatorInterface $perceptualHashCalculator Multi-signal similarity scoring
     * @param LocalDifferenceAnalyzer           $localDiffAnalyzer        Stage B: local blob analysis for score ≥ 95 pairs
     * @param ImagickImageLoader                $imageLoader              Image loader for Stage B pixel extraction
     */
    public function __construct(
        private readonly SafeHashCalculatorInterface $hashCalculator,
        private readonly ProgressReporterInterface $progressReporter,
        private readonly MediaTypeClassifierInterface $mediaTypeClassifier,
        private readonly PerceptualHashCalculatorInterface $perceptualHashCalculator,
        private readonly LocalDifferenceAnalyzer $localDiffAnalyzer,
        private readonly ImagickImageLoader $imageLoader,
    ) {
    }

    /**
     * Sets the maximum RMSE threshold for merging isDuplicateLikely pairs.
     * Pairs with RMSE at or above this threshold are kept as separate sub-groups.
     */
    public function setMaxMergeRmse(float $threshold): void
    {
        $this->maxMergeRmse = $threshold;
    }

    /**
     * Applies content-hash sub-grouping when a naming conflict exists.
     *
     * Returns true when sub-grouping was applied (multiple distinct hashes found),
     * in which case the caller should skip the default suffix assignment.
     * Returns false when sub-grouping is not needed (single file, single hash group,
     * or only companions).
     *
     * @param FileDuplicate                        $fileDuplicate          the duplicate group to process
     * @param Rename|null                          $canonicalRename        the canonical rename entry
     * @param Rename|null                          $companionRename        the Live Photo companion rename entry
     * @param array<string, string>                $contentIdentifierMap   map from source pathname to content identifier
     * @param Closure(SplFileInfo, string): string $targetPathnameResolver resolves (sourceFileInfo, targetFilename) to absolute target path
     * @param array<string, TemporalMetadata|null> $temporalMetadataMap    map from source pathname to temporal metadata (for video duration)
     *
     * @return array<string, string>|null Map from source pathname to cluster root hash key, or null when not needed
     */
    #[Override]
    public function apply(
        FileDuplicate $fileDuplicate,
        ?Rename $canonicalRename,
        ?Rename $companionRename,
        array $contentIdentifierMap,
        Closure $targetPathnameResolver,
        array $temporalMetadataMap = [],
    ): ?array {
        /** @var list<Rename> $nonCompanionRenames */
        $nonCompanionRenames = [];

        // In Live Photo groups, exclude ALL files of the companion's media type
        // (e.g., all MOVs when the companion is a MOV), not just the single
        // detected companion. This prevents video hashes from triggering
        // false naming conflicts with still image hashes.
        $excludeStills = ($companionRename instanceof Rename)
            && $this->mediaTypeClassifier->isLivePhotoStill($companionRename->getSource());

        foreach ($fileDuplicate->getRenames() as $rename) {
            if ($companionRename instanceof Rename) {
                $renameIsStill = $this->mediaTypeClassifier->isLivePhotoStill($rename->getSource());

                // Exclude files that share the companion's media type.
                if ($excludeStills === $renameIsStill) {
                    continue;
                }
            }

            $nonCompanionRenames[] = $rename;
        }

        // No sub-grouping needed for 0 or 1 non-companion files.
        if (count($nonCompanionRenames) <= 1) {
            return null;
        }

        // Compute hashes and build sub-groups keyed by hash.
        /** @var array<string, list<Rename>> $hashGroups */
        $hashGroups = [];

        /** @var array<string, string> $renameToHash Map from source pathname to hash */
        $renameToHash = [];

        $uniqueHashCounter = 0;

        foreach ($nonCompanionRenames as $rename) {
            $sourcePath = $rename->getSource()->getPathname();

            try {
                $hash = $this->hashCalculator->hashFile($rename->getSource(), 'xxh128');
            } catch (HashComputationException $exception) {
                $this->progressReporter->error($exception->getMessage());

                // Treat as unique hash (own sub-group).
                $hash = '__failed_' . $uniqueHashCounter;
                ++$uniqueHashCounter;
            }

            $renameToHash[$sourcePath] = $hash;

            if (!isset($hashGroups[$hash])) {
                $hashGroups[$hash] = [];
            }

            $hashGroups[$hash][] = $rename;
        }

        // If all files have the same hash, this is a pure duplicate group.
        // Fall through to existing logic.
        if (count($hashGroups) <= 1) {
            return null;
        }

        // Merge hash groups that are perceptually similar (near-duplicates).
        // Uses multi-signal scoring (dHash, wHash, HF-energy, color histogram,
        // video duration) to determine if files with different content hashes
        // are visually identical (format conversions, re-imports).
        $hashGroups = $this->mergePerceptuallySimilarGroups(
            $hashGroups,
            $temporalMetadataMap,
            !$companionRename instanceof Rename,
        );

        if (count($hashGroups) <= 1) {
            return null;
        }

        /** @var array<int, true> $nonCompanionLookup */
        $nonCompanionLookup = [];

        foreach ($nonCompanionRenames as $nonCompanionRename) {
            $nonCompanionLookup[spl_object_id($nonCompanionRename)] = true;
        }

        // Heuristic 1: If all companion videos share the same hash, the stills
        // are semantic duplicates (same capture, different JPG encoding/metadata).
        if ($companionRename instanceof Rename) {
            $companionHashes = [];

            foreach ($fileDuplicate->getRenames() as $rename) {
                if (isset($nonCompanionLookup[spl_object_id($rename)])) {
                    continue;
                }

                try {
                    $hash = $this->hashCalculator->hashFile($rename->getSource(), 'xxh128');
                } catch (HashComputationException) {
                    $hash = null;
                }

                if ($hash !== null) {
                    $companionHashes[$hash] = true;
                }
            }

            if (count($companionHashes) === 1) {
                return null;
            }
        }

        // Multiple hashes: naming conflict. The canonical's sub-group keeps the
        // unsuffixed base name; other sub-groups get sequential numbers starting at 002.
        // Note: the SubSecond semantic duplicate heuristic has been moved to
        // DuplicateDetectionService where it has access to TemporalMetadata software tags.
        $canonicalBasename = FileHelper::basenameWithoutExtension($fileDuplicate->getTarget());

        // Determine which merged hash group contains the canonical.
        // After perceptual merge, the canonical's original content hash may have been
        // absorbed into another group (whose root key is a different hash). A direct
        // lookup via $renameToHash would miss this — search the merged groups instead.
        $canonicalHash = null;

        if ($canonicalRename instanceof Rename) {
            $canonicalSource = $canonicalRename->getSource()->getPathname();

            foreach ($hashGroups as $hash => $groupRenames) {
                foreach ($groupRenames as $rename) {
                    if ($rename->getSource()->getPathname() === $canonicalSource) {
                        $canonicalHash = $hash;

                        break 2;
                    }
                }
            }
        }

        // Assign sub-group numbers: canonical's hash gets 0 (no suffix), others get 2, 3, ...
        // Map each merged group key AND all original hashes within that group to the
        // same sub-group number. This ensures lookups via $renameToHash find the correct
        // sub-group even when a perceptual merge absorbed a file's hash into another root.
        $subGroupNumber = 2;

        /** @var list<Rename> $newRenames */
        $newRenames = [];

        /** @var array<string, int> $hashToSubGroup Map from hash to sub-group number (0 = canonical group) */
        $hashToSubGroup = [];

        foreach ($hashGroups as $mergedKey => $groupRenames) {
            $groupNum = ($mergedKey === $canonicalHash) ? 0 : $subGroupNumber++;

            $hashToSubGroup[$mergedKey] = $groupNum;

            foreach ($groupRenames as $rename) {
                $origHash = $renameToHash[$rename->getSource()->getPathname()] ?? null;

                if (($origHash !== null) && ($origHash !== $mergedKey)) {
                    $hashToSubGroup[$origHash] = $groupNum;
                }
            }
        }

        // Build a per-directory total file count for cross-directory conflict resolution.
        // A file that is the only member of this group in its directory has no naming
        // conflict there and can keep the unsuffixed canonical basename.
        $canonicalDir = $canonicalRename?->getSource()->getPath();

        /** @var array<string, int> $dirFileCounts directory → total file count */
        $dirFileCounts = [];

        foreach ($nonCompanionRenames as $rename) {
            $dir                 = $rename->getSource()->getPath();
            $dirFileCounts[$dir] = ($dirFileCounts[$dir] ?? 0) + 1;
        }

        // Now process all hash groups in their assigned order.
        foreach ($hashGroups as $hash => $groupRenames) {
            $groupNumber      = $hashToSubGroup[$hash];
            $isCanonicalGroup = $groupNumber === 0;

            $subGroupBasename = $isCanonicalGroup
                ? $canonicalBasename
                : sprintf('%s-%03d', $canonicalBasename, $groupNumber);

            $duplicateIndex = 1;

            foreach ($groupRenames as $rename) {
                $ext       = strtolower($rename->getTarget()->getExtension());
                $renameDir = $rename->getSource()->getPath();

                // Cross-directory resolution: a file in a different directory than
                // the canonical that is the only file from this group in its directory
                // has no naming conflict — it keeps the unsuffixed canonical basename.
                $isCrossDirNoConflict = ($renameDir !== $canonicalDir)
                    && (!$isCanonicalGroup)
                    && (($dirFileCounts[$renameDir] ?? 0) <= 1);

                if ($isCrossDirNoConflict) {
                    $newTargetFilename = $canonicalBasename . '.' . $ext;
                    $targetPathname    = $targetPathnameResolver($rename->getSource(), $newTargetFilename);

                    $rename->setTarget(new SplFileInfo($targetPathname));
                    $newRenames[] = $rename;

                    continue;
                }

                // In the canonical group, the actual canonical rename gets no suffix.
                // In other groups, prefer the file whose source name already matches
                // the sub-group target (idempotent canonical preference). Falls back
                // to the first file if no name match exists.
                $isSubGroupCanonical = $isCanonicalGroup
                    ? (($canonicalRename instanceof Rename) && ($rename === $canonicalRename))
                    : ($rename === $this->findSubGroupCanonical($groupRenames, $subGroupBasename));

                if ($isSubGroupCanonical) {
                    // Sub-group canonical: no duplicate suffix.
                    $newTargetFilename = $subGroupBasename . '.' . $ext;
                } else {
                    // Duplicate within this sub-group.
                    $newTargetFilename = sprintf(
                        '%s%s%03d.%s',
                        $subGroupBasename,
                        Constants::DUPLICATE_IDENTIFIER,
                        $duplicateIndex,
                        $ext,
                    );

                    ++$duplicateIndex;
                }

                $targetPathname = $targetPathnameResolver(
                    $rename->getSource(),
                    $newTargetFilename,
                );

                $rename->setTarget(new SplFileInfo($targetPathname));
                $newRenames[] = $rename;
            }
        }

        // Handle excluded files (companion media type): each inherits the sub-group
        // number of its Live Photo pair's canonical (via content ID lookup).
        // The first excluded file per LP content ID is the companion (no suffix),
        // additional files of the same content ID are duplicates.
        /** @var array<string, int> $excludedDuplicateCountByContentId */
        $excludedDuplicateCountByContentId = [];

        // Pre-build content-ID -> sub-group map to replace O(n) inner foreach.
        /** @var array<string, int> $contentIdToSubGroup */
        $contentIdToSubGroup = [];

        // Pre-build source filename stem -> sub-group map as fallback for
        // excluded files that lack a content identifier (e.g. MOV companions
        // whose metadata does not include the Live Photo content ID).
        /** @var array<string, int> $sourceBasenameToSubGroup */
        $sourceBasenameToSubGroup = [];

        foreach ($nonCompanionRenames as $stillRename) {
            $stillPath     = $stillRename->getSource()->getPathname();
            $stillHash     = $renameToHash[$stillPath] ?? null;
            $stillSubGroup = (($stillHash !== null) && isset($hashToSubGroup[$stillHash]))
                ? $hashToSubGroup[$stillHash]
                : 0;
            $stillContentId = $contentIdentifierMap[$stillPath] ?? null;

            if ($stillContentId !== null) {
                $contentIdToSubGroup[$stillContentId] = $stillSubGroup;
            }

            $stillBasename                            = FileHelper::basenameWithoutExtension($stillRename->getSource());
            $sourceBasenameToSubGroup[$stillBasename] = $stillSubGroup;
        }

        // Pre-identify the preferred companion per content ID (idempotent preference).
        // When multiple excluded files share a content ID, prefer the one whose source
        // basename already matches the expected target — just like idempotent canonical
        // selection in the non-companion (stills) path.
        /** @var array<string, Rename> $preferredExcludedCompanion */
        $preferredExcludedCompanion = [];

        foreach ($fileDuplicate->getRenames() as $rename) {
            if (isset($nonCompanionLookup[spl_object_id($rename)])) {
                continue;
            }

            $renamePath      = $rename->getSource()->getPathname();
            $renameContentId = $contentIdentifierMap[$renamePath] ?? null;
            $contentIdKey    = $renameContentId ?? '__none_' . $renamePath;

            if (isset($preferredExcludedCompanion[$contentIdKey])) {
                continue;
            }

            if ($renameContentId !== null) {
                $preSubGroup = $contentIdToSubGroup[$renameContentId] ?? 0;
            } else {
                $preBasename = FileHelper::basenameWithoutExtension($rename->getSource());
                $preSubGroup = $sourceBasenameToSubGroup[$preBasename] ?? 0;
            }

            $expectedBasename = $preSubGroup === 0
                ? $canonicalBasename
                : sprintf('%s-%03d', $canonicalBasename, $preSubGroup);

            if (FileHelper::basenameWithoutExtension($rename->getSource()) === $expectedBasename) {
                $preferredExcludedCompanion[$contentIdKey] = $rename;
            }
        }

        foreach ($fileDuplicate->getRenames() as $rename) {
            // Skip files already processed as non-companion (stills).
            if (isset($nonCompanionLookup[spl_object_id($rename)])) {
                continue;
            }

            // Determine which sub-group this excluded file belongs to via content ID.
            $renamePath      = $rename->getSource()->getPathname();
            $renameContentId = $contentIdentifierMap[$renamePath] ?? null;

            if ($renameContentId !== null) {
                $subGroupNum = $contentIdToSubGroup[$renameContentId] ?? 0;
            } else {
                // Fallback: match by source filename stem (e.g. IMG_0001.mov -> IMG_0001).
                // This handles MOV companions that lack a content identifier in their metadata
                // but share the same source filename stem as their paired still image.
                $renameBasename = FileHelper::basenameWithoutExtension($rename->getSource());
                $subGroupNum    = $sourceBasenameToSubGroup[$renameBasename] ?? 0;
            }

            $fileBasename = $subGroupNum === 0
                ? $canonicalBasename
                : sprintf('%s-%03d', $canonicalBasename, $subGroupNum);

            $ext = strtolower($rename->getTarget()->getExtension());

            // Assign companion slot: when a preferred companion exists for this content
            // ID (source basename matches expected target), only that rename gets the
            // unsuffixed companion name. Others become duplicates regardless of order.
            $contentIdKey = $renameContentId ?? '__none_' . $renamePath;
            $preferred    = $preferredExcludedCompanion[$contentIdKey] ?? null;

            $isCompanionSlot = ($preferred !== null)
                ? ($rename === $preferred)
                : !isset($excludedDuplicateCountByContentId[$contentIdKey]);

            if ($isCompanionSlot) {
                $excludedDuplicateCountByContentId[$contentIdKey] ??= 1;
                $newTargetFilename = $fileBasename . '.' . $ext;
            } else {
                $excludedDuplicateCountByContentId[$contentIdKey] ??= 1;

                $dupIdx            = $excludedDuplicateCountByContentId[$contentIdKey];
                $newTargetFilename = sprintf(
                    '%s%s%03d.%s',
                    $fileBasename,
                    Constants::DUPLICATE_IDENTIFIER,
                    $dupIdx,
                    $ext,
                );

                ++$excludedDuplicateCountByContentId[$contentIdKey];
            }

            $targetPathname = $targetPathnameResolver(
                $rename->getSource(),
                $newTargetFilename,
            );

            $rename->setTarget(new SplFileInfo($targetPathname));
            $newRenames[] = $rename;
        }

        // Replace the renames in the fileDuplicate with the newly ordered list.
        $fileDuplicate->setRenames(new RenameList($newRenames));

        // Update the group's canonical target to match the first sub-group's canonical.
        if ($canonicalRename instanceof Rename) {
            $fileDuplicate->setTarget($canonicalRename->getTarget());
        }

        // Build content-based cluster map: source pathname → order-preserving cluster key.
        // The key encodes the subgroup number as a prefix so that alphabetical sort in
        // TargetNameResolver::buildSubgroupMap() reproduces the same ordering as the
        // hash-group iteration order. This preserves subgroup stability across runs
        // while keeping cluster formation filename-free (Regel 1).
        /** @var array<string, string> $clusterMap */
        $clusterMap = [];

        foreach ($hashGroups as $rootHash => $groupRenames) {
            $groupNumber = $hashToSubGroup[$rootHash];
            $clusterKey  = sprintf('%03d_%s', $groupNumber, $rootHash);

            foreach ($groupRenames as $rename) {
                $clusterMap[$rename->getSource()->getPathname()] = $clusterKey;
            }
        }

        // Map companion files to cluster keys via content ID / source basename fallback.
        if ($companionRename instanceof Rename) {
            /** @var array<string, string> $contentIdToClusterKey */
            $contentIdToClusterKey = [];

            /** @var array<string, string> $sourceBasenameToClusterKey */
            $sourceBasenameToClusterKey = [];

            foreach ($nonCompanionRenames as $stillRename) {
                $stillPath  = $stillRename->getSource()->getPathname();
                $clusterKey = $clusterMap[$stillPath] ?? null;

                if ($clusterKey === null) {
                    continue;
                }

                $stillContentId = $contentIdentifierMap[$stillPath] ?? null;

                if ($stillContentId !== null) {
                    $contentIdToClusterKey[$stillContentId] = $clusterKey;
                }

                $stillBasename                              = FileHelper::basenameWithoutExtension($stillRename->getSource());
                $sourceBasenameToClusterKey[$stillBasename] = $clusterKey;
            }

            foreach ($fileDuplicate->getRenames() as $rename) {
                if (isset($nonCompanionLookup[spl_object_id($rename)])) {
                    continue;
                }

                $renamePath      = $rename->getSource()->getPathname();
                $renameContentId = $contentIdentifierMap[$renamePath] ?? null;

                if (($renameContentId !== null) && isset($contentIdToClusterKey[$renameContentId])) {
                    $clusterMap[$renamePath] = $contentIdToClusterKey[$renameContentId];
                } else {
                    $renameBasename = FileHelper::basenameWithoutExtension($rename->getSource());

                    if (isset($sourceBasenameToClusterKey[$renameBasename])) {
                        $clusterMap[$renamePath] = $sourceBasenameToClusterKey[$renameBasename];
                    }
                }
            }
        }

        return $clusterMap;
    }

    /**
     * Merges hash groups whose representative files are perceptually similar.
     * Uses multi-signal scoring (dHash, wHash, HF-energy, color, duration) with
     * Stage B blob analysis for near-identical pairs.
     *
     * @param array<string, list<Rename>>          $hashGroups             Hash groups keyed by content hash.
     * @param array<string, TemporalMetadata|null> $temporalMetadataMap    Temporal metadata keyed by source pathname.
     * @param bool                                 $allowFormatBackupMerge Whether simple format-backup tolerance is allowed.
     *
     * @return array<string, list<Rename>>
     */
    private function mergePerceptuallySimilarGroups(
        array $hashGroups,
        array $temporalMetadataMap,
        bool $allowFormatBackupMerge,
    ): array {
        $hashes = array_keys($hashGroups);
        $count  = count($hashes);

        if ($count <= 1) {
            return $hashGroups;
        }

        $allowExactFormatBackupWindow = $allowFormatBackupMerge && ($count === 2);

        // Pick one representative file per hash group and resolve video duration.
        /** @var array<string, SplFileInfo> $representativeByHash */
        $representativeByHash = [];

        /** @var array<string, float|null> $durationByHash */
        $durationByHash = [];

        foreach ($hashGroups as $hash => $renames) {
            $source                      = $renames[0]->getSource();
            $representativeByHash[$hash] = $source;
            $metadata                    = $temporalMetadataMap[$source->getPathname()] ?? null;
            $durationByHash[$hash]       = $metadata?->getVideoDurationSeconds();
        }

        // Union-find: parent[index] = index of parent in $hashes array.
        /** @var array<int, int> $parent */
        $parent = [];

        for ($hashIndex = 0; $hashIndex < $count; ++$hashIndex) {
            $parent[$hashIndex] = $hashIndex;
        }

        // Stage B image cache: avoids redundant Imagick loads across pairwise comparisons.
        // When comparing pairs (index,j) and (index,k), file index is loaded once instead of twice.
        /** @var array<string, Imagick|null> $stageBImageCache */
        $stageBImageCache = [];

        // Pairwise comparison — 2-stage merge decision.
        // Stage A: multi-signal similarity score.
        // Stage B: local blob analysis for near-identical pairs (score ≥ 95).
        for ($indexA = 0; $indexA < $count; ++$indexA) {
            for ($indexB = $indexA + 1; $indexB < $count; ++$indexB) {
                // Skip pairs already in the same union-find group
                if ($this->findRoot($parent, $indexA) === $this->findRoot($parent, $indexB)) {
                    continue;
                }

                $result = $this->perceptualHashCalculator->similarityScore(
                    $representativeByHash[$hashes[$indexA]],
                    $representativeByHash[$hashes[$indexB]],
                    $durationByHash[$hashes[$indexA]],
                    $durationByHash[$hashes[$indexB]],
                );

                $shouldMerge = false;

                if ($result->isDuplicateLikely()) {
                    $shouldMerge = $this->shouldMergePerceptually(
                        $representativeByHash[$hashes[$indexA]],
                        $representativeByHash[$hashes[$indexB]],
                        $result,
                        $stageBImageCache,
                        $allowExactFormatBackupWindow,
                    );
                }

                if (
                    !$shouldMerge
                    && $allowExactFormatBackupWindow
                ) {
                    $renameA = $hashGroups[$hashes[$indexA]][0];
                    $renameB = $hashGroups[$hashes[$indexB]][0];

                    $shouldMerge = $this->shouldMergeSimpleFormatBackup(
                        $renameA,
                        $renameB,
                        $result,
                        $stageBImageCache,
                    );
                }

                if ($shouldMerge) {
                    $rootA = $this->findRoot($parent, $indexA);
                    $rootB = $this->findRoot($parent, $indexB);

                    if ($rootA !== $rootB) {
                        $parent[$rootB] = $rootA;
                    }
                }
            }
        }

        // Release Stage B image cache
        foreach ($stageBImageCache as $img) {
            $img?->clear();
        }

        // Deterministic root selection: choose the lexicographically smallest hash
        // per connected component, then repoint each node directly to that root.
        // This preserves stable cluster identities without introducing parent cycles.
        $componentMinIndex = [];
        $rootByHashIndex   = [];

        for ($hashIndex = 0; $hashIndex < $count; ++$hashIndex) {
            $root                        = $this->findRoot($parent, $hashIndex);
            $rootByHashIndex[$hashIndex] = $root;
            $currentMinIndex             = $componentMinIndex[$root] ?? null;

            if (($currentMinIndex === null) || ($hashes[$hashIndex] < $hashes[$currentMinIndex])) {
                $componentMinIndex[$root] = $hashIndex;
            }
        }

        for ($hashIndex = 0; $hashIndex < $count; ++$hashIndex) {
            $parent[$hashIndex] = $componentMinIndex[$rootByHashIndex[$hashIndex]];
        }

        // Build merged groups keyed by root's content hash.
        /** @var array<string, list<Rename>> $merged */
        $merged = [];

        for ($hashIndex = 0; $hashIndex < $count; ++$hashIndex) {
            $rootHash = $hashes[$this->findRoot($parent, $hashIndex)];

            if (!isset($merged[$rootHash])) {
                $merged[$rootHash] = [];
            }

            foreach ($hashGroups[$hashes[$hashIndex]] as $rename) {
                $merged[$rootHash][] = $rename;
            }
        }

        return $merged;
    }

    /**
     * Selects the sub-group canonical from a list of renames.
     * Prefers the file whose source basename already matches the sub-group target
     * (idempotent: already correctly named files stay canonical). Falls back to
     * the first file if no match exists.
     *
     * @param list<Rename> $renames
     */
    private function findSubGroupCanonical(array $renames, string $subGroupBasename): Rename
    {
        foreach ($renames as $rename) {
            $sourceBasename = FileHelper::basenameWithoutExtension($rename->getSource());

            if ($sourceBasename === $subGroupBasename) {
                return $rename;
            }
        }

        return $renames[0];
    }

    /**
     * Finds the root of element $index in the union-find structure with path compression.
     *
     * Part of a Disjoint Set Union (DSU) implementation to efficiently group
     * perceptually similar hashes. Path compression ensures near-constant time
     * complexity for subsequent lookups.
     *
     * @param array<int, int> $parent The disjoint set parent array.
     * @param int             $index  The index to find the root for.
     *
     * @return int The root index of the set.
     */
    private function findRoot(array &$parent, int $index): int
    {
        while ($parent[$index] !== $index) {
            $parent[$index] = $parent[$parent[$index]];
            $index          = $parent[$index];
        }

        return $index;
    }

    /**
     * Determines whether two perceptually similar files should be merged into
     * the same cluster.
     *
     * Uses adaptive RMSE thresholds based on dHash distance to distinguish between
     * negligible compression noise and actual image content differences.
     *
     * @param SplFileInfo                 $fileA                        First file to compare.
     * @param SplFileInfo                 $fileB                        Second file to compare.
     * @param SimilarityResult            $similarity                   The pre-calculated similarity.
     * @param array<string, Imagick|null> $imageCache                   Shared image cache for efficiency.
     * @param bool                        $allowExactFormatBackupWindow Whether simple format-backup tolerance is allowed.
     *
     * @return bool True if the files should be merged.
     */
    private function shouldMergePerceptually(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        SimilarityResult $similarity,
        array &$imageCache,
        bool $allowExactFormatBackupWindow,
    ): bool {
        if (!$similarity->isDuplicateLikely()) {
            return false;
        }

        // Both videos → merge (duration already validated by similarity scoring)
        if ($this->mediaTypeClassifier->isVideo($fileA) && $this->mediaTypeClassifier->isVideo($fileB)) {
            $this->debugMergeDecision($fileA, $fileB, $similarity, null, true, 'video pair');

            return true;
        }

        $start   = microtime(true);
        $diff    = $this->analyzeLocalDifferenceCached($fileA, $fileB, $imageCache);
        $elapsed = microtime(true) - $start;

        if (!$diff->success) {
            $this->debugMergeDecision($fileA, $fileB, $similarity, $diff, false, 'analysis failed', $elapsed);

            return false;
        }

        // Chroma veto: color→grayscale conversions have near-zero luma RMSE
        // but large chroma difference. Reject merge regardless of RMSE zone.
        if ($diff->chromaDifference > self::MAX_CHROMA_DIFFERENCE) {
            $reason = sprintf('chroma %.4f > %.4f (color change)', $diff->chromaDifference, self::MAX_CHROMA_DIFFERENCE);

            $this->debugMergeDecision($fileA, $fileB, $similarity, $diff, false, $reason, $elapsed);

            return false;
        }

        // dHash-adaptive RMSE threshold: fewer gradient flips → more likely codec noise → more permissive.
        $safeRmse = match (true) {
            $similarity->dhashDistance === 0 && $allowExactFormatBackupWindow => self::SAFE_MERGE_RMSE_EXACT_FORMAT_BACKUP,
            $similarity->dhashDistance === 0                                  => self::SAFE_MERGE_RMSE_EXACT,
            $similarity->dhashDistance <= 2                                   => self::SAFE_MERGE_RMSE_NEAR,
            default                                                           => self::SAFE_MERGE_RMSE_CHANGED,
        };

        // When the user sets --merge-threshold below the safe threshold, respect their stricter setting.
        $effectiveMergeThreshold = min($safeRmse, $this->maxMergeRmse);
        $merge                   = $diff->rmse <= $effectiveMergeThreshold;
        $reason                  = $merge
            ? sprintf('rmse %.4f <= %.4f (safe zone, dHash=%d)', $diff->rmse, $effectiveMergeThreshold, $similarity->dhashDistance)
            : sprintf('rmse %.4f > %.4f (dHash=%d)', $diff->rmse, $effectiveMergeThreshold, $similarity->dhashDistance);

        $this->debugMergeDecision($fileA, $fileB, $similarity, $diff, $merge, $reason, $elapsed);

        return $merge;
    }

    /**
     * Merges simple HEIC/JPG backup pairs when CI image libraries classify the
     * cheap perceptual pre-score more conservatively than the local pixel check.
     *
     * @param Rename                      $renameA    First rename to compare.
     * @param Rename                      $renameB    Second rename to compare.
     * @param SimilarityResult            $similarity The pre-calculated similarity.
     * @param array<string, Imagick|null> $imageCache Shared image cache for efficiency.
     */
    private function shouldMergeSimpleFormatBackup(
        Rename $renameA,
        Rename $renameB,
        SimilarityResult $similarity,
        array &$imageCache,
    ): bool {
        if (!$this->isStillFormatBackupPair($renameA, $renameB)) {
            return false;
        }

        $fileA = $renameA->getSource();
        $fileB = $renameB->getSource();

        $start   = microtime(true);
        $diff    = $this->analyzeLocalDifferenceCached($fileA, $fileB, $imageCache);
        $elapsed = microtime(true) - $start;

        if (!$diff->success) {
            $this->debugMergeDecision($fileA, $fileB, $similarity, $diff, false, 'format-backup analysis failed', $elapsed);

            return false;
        }

        if ($diff->chromaDifference > self::MAX_CHROMA_DIFFERENCE) {
            $reason = sprintf('format-backup chroma %.4f > %.4f', $diff->chromaDifference, self::MAX_CHROMA_DIFFERENCE);

            $this->debugMergeDecision($fileA, $fileB, $similarity, $diff, false, $reason, $elapsed);

            return false;
        }

        $effectiveMergeThreshold = min(self::SAFE_MERGE_RMSE_EXACT_FORMAT_BACKUP, $this->maxMergeRmse);
        $merge                   = $diff->rmse <= $effectiveMergeThreshold;
        $reason                  = $merge
            ? sprintf('format-backup rmse %.4f <= %.4f', $diff->rmse, $effectiveMergeThreshold)
            : sprintf('format-backup rmse %.4f > %.4f', $diff->rmse, $effectiveMergeThreshold);

        $this->debugMergeDecision($fileA, $fileB, $similarity, $diff, $merge, $reason, $elapsed);

        return $merge;
    }

    private function isStillFormatBackupPair(Rename $renameA, Rename $renameB): bool
    {
        $fileA = $renameA->getSource();
        $fileB = $renameB->getSource();

        if (!$this->mediaTypeClassifier->isLivePhotoStill($fileA) || !$this->mediaTypeClassifier->isLivePhotoStill($fileB)) {
            return false;
        }

        if (strtolower($fileA->getExtension()) === strtolower($fileB->getExtension())) {
            return false;
        }

        return FileHelper::basenameWithoutExtension($renameA->getTarget())
            === FileHelper::basenameWithoutExtension($renameB->getTarget());
    }

    /**
     * Writes a detailed merge-decision debug line to the console.
     *
     * Only outputs when the command is run with debugging enabled (-vvv).
     * Includes perceptual similarity metrics, local difference results (RMSE, chroma),
     * and the final merge verdict with its technical justification.
     *
     * @param SplFileInfo          $fileA      First file in the comparison.
     * @param SplFileInfo          $fileB      Second file in the comparison.
     * @param SimilarityResult     $similarity Perceptual similarity metrics (dHash, color, etc.).
     * @param LocalDiffResult|null $diff       Pixel-level difference metrics or null for videos.
     * @param bool                 $merge      Whether the decision was to merge the groups.
     * @param string               $reason     Technical explanation for the decision.
     * @param float|null           $elapsed    Time taken for the analysis in seconds.
     */
    private function debugMergeDecision(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        SimilarityResult $similarity,
        ?LocalDiffResult $diff,
        bool $merge,
        string $reason,
        ?float $elapsed = null,
    ): void {
        $nameA   = basename($fileA->getPathname());
        $nameB   = basename($fileB->getPathname());
        $verdict = $merge ? '<info>MERGE</info>' : '<comment>NO MERGE</comment>';
        $time    = ($elapsed !== null) ? sprintf(' %.0fms', $elapsed * 1000) : '';
        $rmse    = ($diff instanceof LocalDiffResult) ? sprintf(' rmse=%.4f chroma=%.4f', $diff->rmse, $diff->chromaDifference) : '';

        $this->progressReporter->debug(sprintf(
            '  [merge] %s <-> %s | score=%d dHash=%d color=%.3f |%s | %s (%s)%s',
            $nameA,
            $nameB,
            $similarity->score,
            $similarity->dhashDistance,
            $similarity->colorDistance,
            $rmse,
            $verdict,
            $reason,
            $time,
        ));
    }

    /**
     * Performs a cached local difference analysis between two images.
     *
     * Utilizes a per-group image cache to avoid redundant Imagick loads when the
     * same file appears in multiple pairwise comparisons (O(K) loads instead of O(K^2)).
     *
     * Returns the raw LocalDiffResult without interpretation, allowing the caller
     * to apply zone-based or context-aware merge decisions.
     *
     * @param SplFileInfo                 $fileA      First file to compare.
     * @param SplFileInfo                 $fileB      Second file to compare.
     * @param array<string, Imagick|null> $imageCache Shared cache of normalized Imagick instances.
     *
     * @return LocalDiffResult The computed difference metrics or an unsuccessful result on failure.
     */
    private function analyzeLocalDifferenceCached(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        array &$imageCache,
    ): LocalDiffResult {
        // Videos: return success with zero RMSE (duration-based comparison already done)
        if ($this->mediaTypeClassifier->isVideo($fileA) || $this->mediaTypeClassifier->isVideo($fileB)) {
            return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: true);
        }

        $keyA = $fileA->getPathname();
        $keyB = $fileB->getPathname();

        if (!isset($imageCache[$keyA]) && !array_key_exists($keyA, $imageCache)) {
            $imageCache[$keyA] = $this->imageLoader->loadNormalized($fileA, 512);
        }

        if (!isset($imageCache[$keyB]) && !array_key_exists($keyB, $imageCache)) {
            $imageCache[$keyB] = $this->imageLoader->loadNormalized($fileB, 512);
        }

        $imgA = $imageCache[$keyA];
        $imgB = $imageCache[$keyB];

        // Imagick load failure: return unsuccessful result
        if ((!$imgA instanceof Imagick) || (!$imgB instanceof Imagick)) {
            return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: false);
        }

        return $this->localDiffAnalyzer->analyzeRmse($imgA, $imgB);
    }

    /**
     * Releases all cached hash results to free memory.
     */
    #[Override]
    public function clearCache(): void
    {
        $this->hashCalculator->clearCache();
        $this->perceptualHashCalculator->clearCache();
    }
}
