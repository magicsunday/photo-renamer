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
use MagicSunday\Renamer\Model\Classification\ContentClassificationResult;
use MagicSunday\Renamer\Model\MergeDecision;
use MagicSunday\Renamer\Model\MergeDecisionKind;
use MagicSunday\Renamer\Model\MergeDecisionReason;
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
use function count;
use function min;

/**
 * Shared content classification kernel, independent from naming/execution models.
 * Conservative exact/perceptual rules and finite comparison work live here once;
 * the direct pipeline and legacy adapter consume the same immutable result.
 */
final class ContentClassifier implements ContentClassifierInterface
{
    /**
     * RMSE threshold for dHash==0 pairs (identical gradient structure).
     * Codec changes and local edits can both preserve dHash structure. Stage B
     * still measures pixel differences; zero dHash distance alone permits no merge.
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
    private float $maxMergeRmse = Constants::DEFAULT_MERGE_THRESHOLD;

    /**
     * @param SafeHashCalculatorInterface       $hashCalculator           Computes file content hashes for sub-group keying
     * @param ProgressReporterInterface         $progressReporter         Narrow reporting boundary for recoverable diagnostics
     * @param MediaTypeClassifierInterface      $mediaTypeClassifier      Classifies files as still or video
     * @param PerceptualHashCalculatorInterface $perceptualHashCalculator Multi-signal similarity scoring
     * @param LocalDifferenceAnalyzer           $localDiffAnalyzer        Stage B: grayscale RMSE and mean chroma for likely still matches
     * @param ImagickImageLoader                $imageLoader              Image loader for Stage B pixel extraction
     * @param ComparisonWorkLimit               $comparisonWorkLimit      Finite pair-visit budget per capture group
     */
    public function __construct(
        private readonly SafeHashCalculatorInterface $hashCalculator,
        private readonly ProgressReporterInterface $progressReporter,
        private readonly MediaTypeClassifierInterface $mediaTypeClassifier,
        private readonly PerceptualHashCalculatorInterface $perceptualHashCalculator,
        private readonly LocalDifferenceAnalyzer $localDiffAnalyzer,
        private readonly ImagickImageLoader $imageLoader,
        private readonly ComparisonWorkLimit $comparisonWorkLimit,
    ) {
    }

    /**
     * Sets the selected maximum RMSE without overriding conservative safety
     * caps. Null restores the unconfigured default when the run scope ends,
     * preventing a later legacy workflow from inheriting EXIF preferences.
     *
     * @param float|null $threshold Validated run threshold, or null to restore the default
     *
     * @return void
     */
    #[Override]
    public function setMaxMergeRmse(?float $threshold): void
    {
        $this->maxMergeRmse = $threshold ?? Constants::DEFAULT_MERGE_THRESHOLD;
    }

    /**
     * Computes shared exact/perceptual membership from real source files. A
     * companion family is excluded only when the caller supplies an actual pair;
     * names/identifiers map companions but never override rejected still evidence.
     *
     * @param list<SplFileInfo>                    $files              Stable source encounter order
     * @param SplFileInfo|null                     $canonical          Preferred source for output ranking only
     * @param SplFileInfo|null                     $companion          Paired media family excluded from comparison
     * @param array<string, string>                $contentIdentifiers Explicit source identifiers
     * @param array<string, TemporalMetadata|null> $metadata           Source temporal facts
     * @param Closure(MergeDecision): void|null    $onDecision         Streaming evidence observer
     *
     * @return ContentClassificationResult Immutable source membership
     */
    #[Override]
    public function classify(array $files, ?SplFileInfo $canonical, ?SplFileInfo $companion, array $contentIdentifiers = [], array $metadata = [], ?Closure $onDecision = null): ContentClassificationResult
    {
        $included      = [];
        $excludeStills = ($companion instanceof SplFileInfo) && $this->mediaTypeClassifier->isLivePhotoStill($companion);

        foreach ($files as $file) {
            if (($companion instanceof SplFileInfo) && ($excludeStills === $this->mediaTypeClassifier->isLivePhotoStill($file))) {
                continue;
            }

            $included[] = $file;
        }

        if (count($included) <= 1) {
            return new ContentClassificationResult($included);
        }

        $hashGroups        = [];
        $sourceHashes      = [];
        $unavailableHashes = [];
        $failed            = 0;

        foreach ($included as $file) {
            $path = $file->getPathname();

            try {
                $hash = $this->hashCalculator->hashFile($file, 'xxh128');
            } catch (HashComputationException $exception) {
                $this->progressReporter->error($exception->getMessage());
                $this->reportDecision(new MergeDecision(MergeDecisionKind::Uncertain, MergeDecisionReason::HashReadFailed, $path, null), $onDecision);
                $hash                     = '__failed_' . $failed;
                $unavailableHashes[$hash] = true;
                ++$failed;
            }

            $sourceHashes[$path] = $hash;

            if (isset($hashGroups[$hash])) {
                $this->reportDecision(new MergeDecision(MergeDecisionKind::Exact, MergeDecisionReason::ContentHashMatch, $hashGroups[$hash][0]->getPathname(), $path, contentHash: $hash), $onDecision);
            }

            $hashGroups[$hash] ??= [];
            $hashGroups[$hash][] = $file;
        }

        if (count($hashGroups) > 1) {
            $hashGroups = $this->mergePerceptuallySimilarGroups($hashGroups, $metadata, !$companion instanceof SplFileInfo, $onDecision, $unavailableHashes);
        }

        $canonicalHash = null;
        $rootByPath    = [];

        foreach ($hashGroups as $root => $groupFiles) {
            foreach ($groupFiles as $file) {
                $rootByPath[$file->getPathname()] = $root;

                if (($canonical instanceof SplFileInfo) && ($file->getPathname() === $canonical->getPathname())) {
                    $canonicalHash = $root;
                }
            }
        }

        $companionClusters = [];

        if (($companion instanceof SplFileInfo) && (count($hashGroups) > 1)) {
            $byIdentifier = [];
            $byBasename   = [];

            foreach ($included as $file) {
                $path = $file->getPathname();
                $id   = $contentIdentifiers[$path] ?? null;

                if ($id !== null) {
                    $byIdentifier[$id] = $rootByPath[$path];
                }

                $byBasename[FileHelper::basenameWithoutExtension($file)] = $rootByPath[$path];
            }

            foreach ($files as $file) {
                $path = $file->getPathname();

                if (isset($rootByPath[$path])) {
                    continue;
                }

                $id   = $contentIdentifiers[$path] ?? null;
                $root = (($id !== null) && isset($byIdentifier[$id])) ? $byIdentifier[$id] : ($byBasename[FileHelper::basenameWithoutExtension($file)] ?? null);

                if ($root !== null) {
                    $companionClusters[$path] = $root;
                }
            }
        }

        return new ContentClassificationResult($included, $hashGroups, $sourceHashes, $canonicalHash, $companionClusters);
    }

    /**
     * Merges hash groups whose representative files are perceptually similar.
     * Uses multi-signal scoring (dHash, wHash, HF-energy, color, duration) with
     * Mandatory still-image Stage B RMSE/chroma analysis, including zero dHash distance.
     *
     * @param array<string, list<SplFileInfo>>     $hashGroups             Hash groups keyed by content hash.
     * @param array<string, TemporalMetadata|null> $temporalMetadataMap    Temporal metadata keyed by source pathname.
     * @param bool                                 $allowFormatBackupMerge Whether simple format-backup tolerance is allowed.
     * @param Closure(MergeDecision): void|null    $onDecision             Optional streaming observer for the evidence actually used
     * @param array<string, true>                  $unavailableHashes      Synthetic keys for unreadable contents that must remain independent
     *
     * @return array<string, list<SplFileInfo>>
     */
    private function mergePerceptuallySimilarGroups(
        array $hashGroups,
        array $temporalMetadataMap,
        bool $allowFormatBackupMerge,
        ?Closure $onDecision,
        array $unavailableHashes,
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

        foreach ($hashGroups as $hash => $files) {
            $source                      = $files[0];
            $representativeByHash[$hash] = $source;
            $metadata                    = $temporalMetadataMap[$source->getPathname()] ?? null;
            $durationByHash[$hash]       = $metadata?->getVideoDurationSeconds();
        }

        $components = new DisjointSetUnion($count);

        // Stage B image cache: avoids redundant Imagick loads across pairwise comparisons.
        // When comparing pairs (index,j) and (index,k), file index is loaded once instead of twice.
        /** @var array<string, Imagick|null> $stageBImageCache */
        $stageBImageCache = [];
        $visitedPairs     = 0;

        // Pairwise comparison — 2-stage merge decision.
        // Stage A: multi-signal similarity score.
        // Stage B: mandatory still-image RMSE/chroma, including zero dHash distance.
        try {
            for ($indexA = 0; $indexA < $count; ++$indexA) {
                for ($indexB = $indexA + 1; $indexB < $count; ++$indexB) {
                    $this->comparisonWorkLimit->assertWithinLimit(++$visitedPairs, 'capture-group perceptual analysis');

                    // Skip pairs already in the same union-find group
                    if ($components->find($indexA) === $components->find($indexB)) {
                        continue;
                    }

                    if (isset($unavailableHashes[$hashes[$indexA]]) || isset($unavailableHashes[$hashes[$indexB]])) {
                        $this->reportDecision(new MergeDecision(
                            MergeDecisionKind::Uncertain,
                            MergeDecisionReason::HashReadFailed,
                            $representativeByHash[$hashes[$indexA]]->getPathname(),
                            $representativeByHash[$hashes[$indexB]]->getPathname(),
                        ), $onDecision);

                        continue;
                    }

                    $result = $this->perceptualHashCalculator->similarityScore(
                        $representativeByHash[$hashes[$indexA]],
                        $representativeByHash[$hashes[$indexB]],
                        $durationByHash[$hashes[$indexA]],
                        $durationByHash[$hashes[$indexB]],
                    );

                    $decision = $this->decidePerceptualMerge(
                        $representativeByHash[$hashes[$indexA]],
                        $representativeByHash[$hashes[$indexB]],
                        $result,
                        $stageBImageCache,
                        $allowExactFormatBackupWindow,
                    );
                    $this->reportDecision($decision, $onDecision);

                    if ($decision->permitsMerge()) {
                        $components->union($indexA, $indexB);
                    }
                }
            }
        } finally {
            // Release native images even when a work limit or analysis fails.
            foreach ($stageBImageCache as $img) {
                $img?->clear();
            }
        }

        // Deterministic root selection: choose the lexicographically smallest hash
        // per connected component, then repoint each node directly to that root.
        // This preserves stable cluster identities without introducing parent cycles.
        $componentMinIndex = [];
        $rootByHashIndex   = [];

        for ($hashIndex = 0; $hashIndex < $count; ++$hashIndex) {
            $root                        = $components->find($hashIndex);
            $rootByHashIndex[$hashIndex] = $root;
            $currentMinIndex             = $componentMinIndex[$root] ?? null;

            if (($currentMinIndex === null) || ($hashes[$hashIndex] < $hashes[$currentMinIndex])) {
                $componentMinIndex[$root] = $hashIndex;
            }
        }

        // Build merged groups keyed by root's content hash.
        /** @var array<string, list<SplFileInfo>> $merged */
        $merged = [];

        for ($hashIndex = 0; $hashIndex < $count; ++$hashIndex) {
            $root     = $rootByHashIndex[$hashIndex];
            $rootHash = $hashes[$componentMinIndex[$root]];

            $merged[$rootHash] ??= [];

            foreach ($hashGroups[$hashes[$hashIndex]] as $file) {
                $merged[$rootHash][] = $file;
            }
        }

        return $merged;
    }

    /**
     * Makes one explicit, terminal decision from the available visual evidence.
     * Missing evidence stays uncertain; similarity, chroma and RMSE rejections
     * cannot be upgraded by any later companion or naming heuristic.
     *
     * @param SplFileInfo                 $fileA                        First source
     * @param SplFileInfo                 $fileB                        Second source
     * @param SimilarityResult            $similarity                   Stage A evidence, including signal availability
     * @param array<string, Imagick|null> $imageCache                   Per-group Stage B decode cache
     * @param bool                        $allowExactFormatBackupWindow Whether the existing codec tolerance applies
     *
     * @return MergeDecision Verdict and evidence actually used by clustering
     */
    private function decidePerceptualMerge(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        SimilarityResult $similarity,
        array &$imageCache,
        bool $allowExactFormatBackupWindow,
    ): MergeDecision {
        $leftPath  = $fileA->getPathname();
        $rightPath = $fileB->getPathname();

        if (!$similarity->analysisAvailable) {
            return new MergeDecision(MergeDecisionKind::Uncertain, MergeDecisionReason::SignalUnavailable, $leftPath, $rightPath);
        }

        if (!$similarity->isDuplicateLikely()) {
            return new MergeDecision(
                MergeDecisionKind::Different,
                $similarity->isEditedVariant() ? MergeDecisionReason::EditedVariant : MergeDecisionReason::PerceptualRejected,
                $leftPath,
                $rightPath,
                similarityScore: $similarity->score,
                dhashDistance: $similarity->dhashDistance,
            );
        }

        // This is perceptual video evidence, never exact audio/container identity.
        if ($this->mediaTypeClassifier->isVideo($fileA) && $this->mediaTypeClassifier->isVideo($fileB)) {
            return new MergeDecision(MergeDecisionKind::Perceptual, MergeDecisionReason::VideoMatch, $leftPath, $rightPath, similarityScore: $similarity->score, dhashDistance: $similarity->dhashDistance);
        }

        $diff = $this->analyzeLocalDifferenceCached($fileA, $fileB, $imageCache);

        if (!$diff->success) {
            return new MergeDecision(MergeDecisionKind::Uncertain, MergeDecisionReason::LocalAnalysisFailed, $leftPath, $rightPath, similarityScore: $similarity->score, dhashDistance: $similarity->dhashDistance);
        }

        if ($diff->chromaDifference > self::MAX_CHROMA_DIFFERENCE) {
            return new MergeDecision(MergeDecisionKind::Different, MergeDecisionReason::ChromaVeto, $leftPath, $rightPath, similarityScore: $similarity->score, dhashDistance: $similarity->dhashDistance, rmse: $diff->rmse, chromaDifference: $diff->chromaDifference);
        }

        $safeRmse = match (true) {
            $similarity->dhashDistance === 0 && $allowExactFormatBackupWindow => self::SAFE_MERGE_RMSE_EXACT_FORMAT_BACKUP,
            $similarity->dhashDistance === 0                                  => self::SAFE_MERGE_RMSE_EXACT,
            $similarity->dhashDistance <= 2                                   => self::SAFE_MERGE_RMSE_NEAR,
            default                                                           => self::SAFE_MERGE_RMSE_CHANGED,
        };
        $threshold = min($safeRmse, $this->maxMergeRmse);
        $merge     = $diff->rmse <= $threshold;

        return new MergeDecision(
            $merge ? MergeDecisionKind::Perceptual : MergeDecisionKind::Different,
            $merge ? MergeDecisionReason::VisualMatch : MergeDecisionReason::RmseVeto,
            $leftPath,
            $rightPath,
            similarityScore: $similarity->score,
            dhashDistance: $similarity->dhashDistance,
            rmse: $diff->rmse,
            chromaDifference: $diff->chromaDifference,
            mergeThreshold: $threshold,
        );
    }

    /**
     * Streams one typed verdict to its observer and verbose diagnostics. Neither
     * the service nor its reporter stores a quadratic comparison history.
     *
     * @param MergeDecision                     $decision   Final verdict and actual evidence
     * @param Closure(MergeDecision): void|null $onDecision Optional caller-owned observer
     */
    private function reportDecision(MergeDecision $decision, ?Closure $onDecision): void
    {
        $this->progressReporter->debug('[classification] ' . $decision->describe());

        if ($onDecision instanceof Closure) {
            $onDecision($decision);
        }
    }

    /**
     * Performs a cached local difference analysis between two images.
     *
     * Utilizes a per-group image cache to avoid redundant Imagick loads when the
     * same file appears in multiple pairwise comparisons (O(K) loads instead of O(K^2)).
     *
     * Returns measurements and availability without interpreting thresholds;
     * decidePerceptualMerge() owns the conservative RMSE/chroma policy.
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
            return new LocalDiffResult(0.0, success: true);
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
            return new LocalDiffResult(0.0, success: false);
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
