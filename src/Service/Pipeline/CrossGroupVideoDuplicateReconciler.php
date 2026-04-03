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
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcherInterface;

use function count;
use function round;
use function sprintf;
use function usort;

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
     */
    public function __construct(
        private MediaCompatibilityPolicy $mediaCompatibilityPolicy,
        private VideoStreamFingerprintMatcherInterface $videoStreamFingerprintMatcher,
        private ProgressReporterInterface $progressReporter,
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
        $comparisons     = $this->buildComparisons($candidateVideos);
        $comparisonCount = count($comparisons);

        if ($comparisonCount === 0) {
            return;
        }

        $this->progressReporter->section('<fg=cyan>Reconciling cross-group videos</>');
        $this->progressReporter->startProgress($comparisonCount);

        foreach ($comparisons as $comparison) {
            $leftGroup  = $groups->get($comparison['leftGroupKey']);
            $rightGroup = $groups->get($comparison['rightGroupKey']);

            if (!$leftGroup instanceof AssetGroup || !$rightGroup instanceof AssetGroup) {
                $this->progressReporter->advance();

                continue;
            }

            $leftItem  = $leftGroup->getItemByPath($comparison['leftPath']);
            $rightItem = $rightGroup->getItemByPath($comparison['rightPath']);

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

        $this->progressReporter->finish();
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
     * @return array<string, list<array{groupKey: string, item: AssetItem}>> Videos bucketed by normalized duration
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

                $bucketedVideos['duration:' . sprintf('%.3f', round($duration, 3))][] = [
                    'groupKey' => $group->groupKey,
                    'item'     => $item,
                ];
            }
        }

        foreach ($bucketedVideos as &$bucketEntries) {
            usort(
                $bucketEntries,
                static fn (array $entryA, array $entryB): int => $entryA['item']->file->getPathname() <=> $entryB['item']->file->getPathname(),
            );
        }

        return $bucketedVideos;
    }

    /**
     * Builds the flat comparison plan the reconciler will execute.
     *
     * @param array<string, list<array{groupKey: string, item: AssetItem}>> $candidateVideos Videos bucketed by normalized duration
     *
     * @return list<array{leftGroupKey: string, leftPath: string, rightGroupKey: string, rightPath: string}> Flat cross-group comparison plan
     */
    private function buildComparisons(array $candidateVideos): array
    {
        $comparisons = [];

        foreach ($candidateVideos as $bucketEntries) {
            $entryCount = count($bucketEntries);

            for ($leftIndex = 0; $leftIndex < $entryCount; ++$leftIndex) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < $entryCount; ++$rightIndex) {
                    if ($bucketEntries[$leftIndex]['groupKey'] === $bucketEntries[$rightIndex]['groupKey']) {
                        continue;
                    }

                    $comparisons[] = [
                        'leftGroupKey'  => $bucketEntries[$leftIndex]['groupKey'],
                        'leftPath'      => $bucketEntries[$leftIndex]['item']->file->getPathname(),
                        'rightGroupKey' => $bucketEntries[$rightIndex]['groupKey'],
                        'rightPath'     => $bucketEntries[$rightIndex]['item']->file->getPathname(),
                    ];
                }
            }
        }

        return $comparisons;
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
        [$targetGroup, $targetItem, $sourceGroup, $sourceItem] = $this->determineMergeDirection(
            $leftGroup,
            $leftItem,
            $rightGroup,
            $rightItem,
        );

        $existing = $targetGroup->getItemByPath($sourceItem->file->getPathname());

        if ($existing instanceof AssetItem) {
            return;
        }

        $targetGroup->addItem($sourceItem);
        $targetGroup->addDecision(sprintf(
            'Merged cross-group video duplicate: %s matched %s via stream hash',
            $sourceItem->file->getBasename(),
            $targetItem->file->getBasename(),
        ));

        $sourceGroup->removeItem($sourceItem);

        if ($sourceGroup->itemCount() === 0) {
            $groups->remove($sourceGroup->groupKey);
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
     * @return array{0: AssetGroup, 1: AssetItem, 2: AssetGroup, 3: AssetItem} Target group/item followed by source group/item
     */
    private function determineMergeDirection(
        AssetGroup $leftGroup,
        AssetItem $leftItem,
        AssetGroup $rightGroup,
        AssetItem $rightItem,
    ): array {
        if ($leftGroup->groupKey < $rightGroup->groupKey) {
            return [$leftGroup, $leftItem, $rightGroup, $rightItem];
        }

        if ($rightGroup->groupKey < $leftGroup->groupKey) {
            return [$rightGroup, $rightItem, $leftGroup, $leftItem];
        }

        if ($leftItem->file->getPathname() <= $rightItem->file->getPathname()) {
            return [$leftGroup, $leftItem, $rightGroup, $rightItem];
        }

        return [$rightGroup, $rightItem, $leftGroup, $leftItem];
    }
}
