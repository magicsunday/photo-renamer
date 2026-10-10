<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use Generator;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcherInterface;

use function count;
use function intdiv;
use function round;
use function sprintf;
use function usort;

use const PHP_INT_MAX;

/**
 * Reconciles exact-content videos that were split into different capture groups.
 *
 * EXIF-based grouping uses timestamp-derived basenames, so metadata drift can send
 * byte-identical videos down separate group branches before subgroup classification
 * begins. This reconciler runs in the narrow window between build and classify,
 * uses cheap guards first, and then asks VideoStreamFingerprintMatcher for exact
 * stream evidence before merging or surfacing a review candidate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CrossGroupVideoDuplicateReconciler implements CrossGroupVideoDuplicateReconcilerInterface
{
    /**
     * @param MediaCompatibilityPolicy               $mediaCompatibilityPolicy      Distinguishes video/still families consistently with the rest of the project
     * @param VideoStreamFingerprintMatcherInterface $videoStreamFingerprintMatcher Stream-level exact-content matcher for videos
     * @param ProgressReporterInterface              $progressReporter              Narrow reporting boundary for progress headings and diagnostics
     * @param ComparisonWorkLimit                    $comparisonWorkLimit           Finite pair-visit budget for the entire cross-group batch
     */
    public function __construct(
        private MediaCompatibilityPolicy $mediaCompatibilityPolicy,
        private VideoStreamFingerprintMatcherInterface $videoStreamFingerprintMatcher,
        private ProgressReporterInterface $progressReporter,
        private ComparisonWorkLimit $comparisonWorkLimit,
    ) {
    }

    /**
     * Reconciles cross-group video duplicates using duration buckets and stream hashes.
     *
     * Only videos with a known normalized duration are considered. Exact duplicate
     * matches move the duplicate item into the earlier anchor group; review-only
     * matches are recorded in PipelineContext for later output projection.
     *
     * @param AssetGroupCollection $groups  Capture groups discovered by CaptureGroupBuilder
     * @param PipelineContext      $context Mutable pipeline context for review findings
     */
    public function reconcile(AssetGroupCollection $groups, PipelineContext $context): void
    {
        $candidateVideos = $this->collectCandidateVideos($groups);
        $started         = false;

        try {
            foreach ($this->buildComparisons($candidateVideos, $groups) as $comparison) {
                if (!$started) {
                    $this->progressReporter->section('<fg=cyan>Reconciling cross-group videos</>');
                    $this->progressReporter->startProgress(0);
                    $started = true;
                }

                $leftGroup  = $groups->get($comparison->leftGroupKey);
                $rightGroup = $groups->get($comparison->rightGroupKey);

                if (!$leftGroup instanceof AssetGroup || !$rightGroup instanceof AssetGroup) {
                    $this->progressReporter->advance();

                    continue;
                }

                $leftItem  = $leftGroup->getItemByPath($comparison->leftPath);
                $rightItem = $rightGroup->getItemByPath($comparison->rightPath);

                if (!$leftItem instanceof AssetItem || !$rightItem instanceof AssetItem) {
                    $this->progressReporter->advance();

                    continue;
                }

                if (!$this->shouldCompare($leftItem, $rightItem)) {
                    $this->progressReporter->advance();

                    continue;
                }

                $match = $this->videoStreamFingerprintMatcher->match($leftItem->file, $rightItem->file);
                $this->progressReporter->advance();

                if ($match->isExactDuplicate()) {
                    $this->mergeExactDuplicate($groups, $leftGroup, $leftItem, $rightGroup, $rightItem);

                    continue;
                }

                if ($match->isCandidate()) {
                    $context->addVideoDuplicateCandidate(new VideoDuplicateCandidate(
                        $leftItem->file->getPathname(),
                        $rightItem->file->getPathname(),
                        $match->reviewReason ?? 'cross-group video review required',
                    ));
                }
            }
        } finally {
            if ($started) {
                $this->progressReporter->finish();
            }
        }
    }

    /**
     * Returns true when cross-group stream comparison is allowed for the pair.
     *
     * Live Photo content identifiers remain the stronger identity signal. When both
     * videos already expose non-null content identifiers and those identifiers differ,
     * the reconciler must not let stream equality override that disagreement.
     *
     * The stream-based fallback therefore only applies when at least one side lacks
     * a usable content identifier or when both sides agree on the same identifier.
     *
     * @param AssetItem $leftItem  First video candidate from the comparison plan
     * @param AssetItem $rightItem Second video candidate from the comparison plan
     *
     * @return bool True when the matcher may evaluate stream-level identity
     */
    private function shouldCompare(AssetItem $leftItem, AssetItem $rightItem): bool
    {
        if (
            ($leftItem->contentIdentifier !== null)
            && ($rightItem->contentIdentifier !== null)
            && ($leftItem->contentIdentifier !== $rightItem->contentIdentifier)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Collects videos into normalized-duration buckets for conservative comparison.
     *
     * Missing duration metadata currently blocks Feature Track A because the cheap
     * duration guard is part of the safety policy.
     *
     * @param AssetGroupCollection $groups Capture groups to inspect
     *
     * @return array<string, list<DurationBucketedVideoCandidate>> Videos bucketed by normalized duration
     */
    private function collectCandidateVideos(AssetGroupCollection $groups): array
    {
        $bucketedVideos = [];

        foreach ($groups as $group) {
            $items = $group->getItems();
            usort($items, static fn (AssetItem $itemA, AssetItem $itemB): int => $itemA->file->getPathname() <=> $itemB->file->getPathname());

            foreach ($items as $item) {
                if (!$this->mediaCompatibilityPolicy->isVideo($item->file)) {
                    continue;
                }

                $duration = $item->metadata?->getVideoDurationSeconds();

                if ($duration === null) {
                    continue;
                }

                $bucketedVideos['duration:' . sprintf('%.3f', round($duration, 3))][] = new DurationBucketedVideoCandidate(
                    $group->groupKey,
                    $item,
                );
            }
        }

        foreach ($bucketedVideos as &$bucketEntries) {
            usort(
                $bucketEntries,
                static fn (DurationBucketedVideoCandidate $entryA, DurationBucketedVideoCandidate $entryB): int => $entryA->item->file->getPathname() <=> $entryB->item->file->getPathname(),
            );
        }

        return $bucketedVideos;
    }

    /**
     * Streams eligible pairs in the original bucket/path order using linear storage.
     *
     * Removed or moved left candidates skip an entire stale row. Every remaining
     * inner-loop visit consumes budget, including cheap group/membership exclusions.
     * A linear content-ID index excludes conflicting known identities without
     * visiting their pairs. Unknown IDs still compare against every identity;
     * indexed right-hand candidates preserve the original nested pathname order.
     *
     * @param array<string, list<DurationBucketedVideoCandidate>> $candidateVideos Videos bucketed by normalized duration
     * @param AssetGroupCollection                                $groups          Live collection reflecting earlier exact merges
     *
     * @return Generator<int, CrossGroupVideoComparisonPlan> Lazily generated eligible comparisons
     */
    private function buildComparisons(array $candidateVideos, AssetGroupCollection $groups): Generator
    {
        $visitedPairs = 0;

        foreach ($candidateVideos as $bucketEntries) {
            $entryCount        = count($bucketEntries);
            $groupKeys         = [];
            $identifierIndexes = [];
            $unknownIndexes    = [];

            foreach ($bucketEntries as $index => $entry) {
                $groupKeys[$entry->groupKey] = true;

                if ($entry->item->contentIdentifier === null) {
                    $unknownIndexes[] = $index;
                } else {
                    $identifierIndexes['id:' . $entry->item->contentIdentifier][] = $index;
                }
            }

            if (count($groupKeys) < 2) {
                continue;
            }

            for ($leftIndex = 0; $leftIndex < $entryCount; ++$leftIndex) {
                $left      = $bucketEntries[$leftIndex];
                $leftGroup = $groups->get($left->groupKey);

                if ((!$leftGroup instanceof AssetGroup) || (!$leftGroup->getItemByPath($left->item->file->getPathname()) instanceof AssetItem)) {
                    continue;
                }

                foreach ($this->rightCandidateIndexes($left->item->contentIdentifier, $identifierIndexes, $unknownIndexes, $leftIndex, $entryCount) as $rightIndex) {
                    $this->comparisonWorkLimit->assertWithinLimit(++$visitedPairs, 'cross-group video batch');
                    $right = $bucketEntries[$rightIndex];

                    if (($left->groupKey === $right->groupKey) || !$this->shouldCompare($left->item, $right->item)) {
                        continue;
                    }

                    $rightGroup = $groups->get($right->groupKey);

                    if ((!$rightGroup instanceof AssetGroup) || (!$rightGroup->getItemByPath($right->item->file->getPathname()) instanceof AssetItem)) {
                        continue;
                    }

                    yield new CrossGroupVideoComparisonPlan(
                        $left->groupKey,
                        $left->item->file->getPathname(),
                        $right->groupKey,
                        $right->item->file->getPathname(),
                    );
                }
            }
        }
    }

    /**
     * Streams right-side indices allowed by the existing content-ID policy.
     *
     * Known IDs compare only to equal IDs and unknown IDs. Merge those two sorted
     * index lists rather than concatenating them, preserving original pair order.
     * An unknown left ID retains every later candidate, exactly as before.
     *
     * @param string|null              $contentIdentifier Left candidate's normalized content ID
     * @param array<string, list<int>> $identifierIndexes Sorted indices per prefixed known ID
     * @param list<int>                $unknownIndexes    Sorted indices lacking a content ID
     * @param int                      $leftIndex         Left candidate's index in pathname order
     * @param int                      $entryCount        Total duration-bucket candidates
     *
     * @return Generator<int, int> Eligible right indices in ascending order
     */
    private function rightCandidateIndexes(?string $contentIdentifier, array $identifierIndexes, array $unknownIndexes, int $leftIndex, int $entryCount): Generator
    {
        if ($contentIdentifier === null) {
            for ($index = $leftIndex + 1; $index < $entryCount; ++$index) {
                yield $index;
            }

            return;
        }

        $matchingIndexes  = $identifierIndexes['id:' . $contentIdentifier];
        $matchingPosition = $this->firstPositionAfter($matchingIndexes, $leftIndex);
        $unknownPosition  = $this->firstPositionAfter($unknownIndexes, $leftIndex);

        while (true) {
            $matchingIndex = $matchingIndexes[$matchingPosition] ?? PHP_INT_MAX;
            $unknownIndex  = $unknownIndexes[$unknownPosition] ?? PHP_INT_MAX;

            if (($matchingIndex === PHP_INT_MAX) && ($unknownIndex === PHP_INT_MAX)) {
                return;
            }

            if ($matchingIndex < $unknownIndex) {
                yield $matchingIndex;
                ++$matchingPosition;
            } else {
                yield $unknownIndex;
                ++$unknownPosition;
            }
        }
    }

    /**
     * Finds the first later candidate in logarithmic work, avoiding repeated
     * linear prefix scans when many Live Photos share the same duration.
     *
     * @param list<int> $indexes   Sorted indices in a content-ID partition
     * @param int       $leftIndex Current candidate's index
     *
     * @return int Position of the first later index, or the list length
     */
    private function firstPositionAfter(array $indexes, int $leftIndex): int
    {
        $lower = 0;
        $upper = count($indexes);

        while ($lower < $upper) {
            $middle = $lower + intdiv($upper - $lower, 2);

            if ($indexes[$middle] <= $leftIndex) {
                $lower = $middle + 1;
            } else {
                $upper = $middle;
            }
        }

        return $lower;
    }

    /**
     * Moves an exact-duplicate video into the earlier anchor group and prunes empty groups.
     *
     * The reconciler deliberately moves only the matching video item, not the whole
     * source group. This keeps unrelated stills or additional videos in their original
     * groups and avoids over-merging across captures.
     *
     * @param AssetGroupCollection $groups     Group collection that may need empty-group pruning
     * @param AssetGroup           $leftGroup  First group from the comparison plan
     * @param AssetItem            $leftItem   Matching video item from the first group
     * @param AssetGroup           $rightGroup Second group from the comparison plan
     * @param AssetItem            $rightItem  Matching video item from the second group
     */
    private function mergeExactDuplicate(
        AssetGroupCollection $groups,
        AssetGroup $leftGroup,
        AssetItem $leftItem,
        AssetGroup $rightGroup,
        AssetItem $rightItem,
    ): void {
        $mergeDecision = $this->determineMergeDirection(
            $leftGroup,
            $leftItem,
            $rightGroup,
            $rightItem,
        );

        $existing = $mergeDecision->targetGroup->getItemByPath($mergeDecision->sourceItem->file->getPathname());

        if ($existing instanceof AssetItem) {
            return;
        }

        $mergeDecision->targetGroup->addItem($mergeDecision->sourceItem);
        $mergeDecision->targetGroup->addDecision(sprintf(
            'Merged cross-group video duplicate: %s matched %s via stream hash',
            $mergeDecision->sourceItem->file->getBasename(),
            $mergeDecision->targetItem->file->getBasename(),
        ));

        $mergeDecision->sourceGroup->removeItem($mergeDecision->sourceItem);

        if ($mergeDecision->sourceGroup->itemCount() === 0) {
            $groups->remove($mergeDecision->sourceGroup->groupKey);
        }
    }

    /**
     * Chooses the earlier group as merge anchor and keeps path ordering only as a tiebreaker.
     *
     * Group keys encode the capture timestamp basename, so they are the most stable
     * signal for the intended anchor direction. Path ordering is only used when two
     * groups somehow compare equal on group key.
     *
     * @param AssetGroup $leftGroup  First group from the flat comparison plan
     * @param AssetItem  $leftItem   Matching video item from the first group
     * @param AssetGroup $rightGroup Second group from the flat comparison plan
     * @param AssetItem  $rightItem  Matching video item from the second group
     *
     * @return CrossGroupVideoMergeDecision Explicit target/source merge direction
     */
    private function determineMergeDirection(
        AssetGroup $leftGroup,
        AssetItem $leftItem,
        AssetGroup $rightGroup,
        AssetItem $rightItem,
    ): CrossGroupVideoMergeDecision {
        if ($leftGroup->groupKey < $rightGroup->groupKey) {
            return new CrossGroupVideoMergeDecision($leftGroup, $leftItem, $rightGroup, $rightItem);
        }

        if ($rightGroup->groupKey < $leftGroup->groupKey) {
            return new CrossGroupVideoMergeDecision($rightGroup, $rightItem, $leftGroup, $leftItem);
        }

        if ($leftItem->file->getPathname() <= $rightItem->file->getPathname()) {
            return new CrossGroupVideoMergeDecision($leftGroup, $leftItem, $rightGroup, $rightItem);
        }

        return new CrossGroupVideoMergeDecision($rightGroup, $rightItem, $leftGroup, $leftItem);
    }
}
