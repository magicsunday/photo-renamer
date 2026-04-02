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
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_keys;
use function is_array;
use function round;
use function sprintf;

/**
 * Reconciles orphan MOV groups into existing Live Photo groups before subgroup classification.
 *
 * The EXIF pipeline can legitimately end up with a standalone video-only group when a MOV carries
 * the wrong content identifier even though another MOV already forms the valid still+video Live
 * Photo pair. This collaborator owns the conservative reconciliation pass that compares such orphan
 * videos against already valid companion videos and merges only perceptually identical matches.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OrphanLivePhotoVideoReconciler
{
    /**
     * @param MediaTypeClassifierInterface      $mediaTypeClassifier      Classifies files as Live Photo stills or videos
     * @param PerceptualHashCalculatorInterface $perceptualHashCalculator Multi-frame similarity scoring for candidate videos
     * @param SymfonyStyle                      $io                       Console output used for progress reporting
     */
    public function __construct(
        private MediaTypeClassifierInterface $mediaTypeClassifier,
        private PerceptualHashCalculatorInterface $perceptualHashCalculator,
        private SymfonyStyle $io,
    ) {
    }

    /**
     * Reconciles standalone orphan MOV groups into already valid Live Photo groups.
     *
     * The reconciliation is intentionally conservative: only singleton video groups with a content
     * identifier are considered, and expensive perceptual comparison is attempted only for videos
     * with identical normalized durations. When a duplicate-likely match is found, the orphan video
     * is merged into the valid Live Photo group so later subgrouping can mark it as a duplicate.
     *
     * @param AssetGroupCollection $groups Groups discovered by CaptureGroupBuilder
     */
    public function reconcile(AssetGroupCollection $groups): void
    {
        $candidateCompanions = $this->collectValidCompanionCandidates($groups);
        $orphanVideos        = $this->collectOrphanVideos($groups);

        if (($candidateCompanions === []) || ($orphanVideos === [])) {
            return;
        }

        $comparisonCount = $this->countReconciliationComparisons($orphanVideos, $candidateCompanions);

        $this->io->newLine();
        $this->io->text('<fg=cyan>Reconciling orphan Live Photo videos</>');

        $progressBar = $this->io->createProgressBar(max($comparisonCount, 1));
        $progressBar->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar->start();

        $this->mergeOrphanVideoDuplicatesIntoLivePhotoGroups(
            $groups,
            $candidateCompanions,
            $orphanVideos,
            $progressBar,
        );

        $progressBar->finish();
        $this->io->newLine();
    }

    /**
     * Merges standalone video-only groups into existing Live Photo groups when the
     * video is perceptually identical to an already valid companion video.
     *
     * @param AssetGroupCollection                            $groups              Groups discovered by CaptureGroupBuilder
     * @param list<array{group: AssetGroup, item: AssetItem}> $candidateCompanions Valid existing companion videos
     * @param list<array{groupKey: string, item: AssetItem}>  $orphanVideos        Orphan singleton video groups with their video item
     * @param ProgressBar                                     $progressBar         Progress bar for CLI feedback
     */
    private function mergeOrphanVideoDuplicatesIntoLivePhotoGroups(
        AssetGroupCollection $groups,
        array $candidateCompanions,
        array $orphanVideos,
        ProgressBar $progressBar,
    ): void {
        foreach ($orphanVideos as $orphanEntry) {
            $groupKey = $orphanEntry['groupKey'];
            $group    = $groups->get($groupKey);

            if (!$group instanceof AssetGroup) {
                continue;
            }

            $orphanVideo = $orphanEntry['item'];

            /** @var array{group: AssetGroup, item: AssetItem, score: int}|null $bestMatch */
            $bestMatch = null;
            $bestScore = -1;

            foreach ($candidateCompanions as $candidate) {
                if (!$this->isReconcilableCandidate($groupKey, $orphanVideo, $candidate)) {
                    continue;
                }

                $similarity = $this->perceptualHashCalculator->similarityScore(
                    $orphanVideo->file,
                    $candidate['item']->file,
                    $orphanVideo->metadata?->getVideoDurationSeconds(),
                    $candidate['item']->metadata?->getVideoDurationSeconds(),
                );
                $progressBar->advance();

                if (!$similarity->isDuplicateLikely()) {
                    continue;
                }

                if ($similarity->score > $bestScore) {
                    $bestScore = $similarity->score;
                    $bestMatch = [
                        'group' => $candidate['group'],
                        'item'  => $candidate['item'],
                        'score' => $similarity->score,
                    ];
                }
            }

            $this->perceptualHashCalculator->clearCache();

            if (!is_array($bestMatch)) {
                continue;
            }

            $targetGroup = $bestMatch['group'];
            $targetVideo = $bestMatch['item'];

            $targetGroup->addItem($orphanVideo);
            $targetGroup->addDecision(sprintf(
                'Merged orphan video duplicate: %s matched %s (score %d, content-id %s vs %s)',
                $orphanVideo->file->getBasename(),
                $targetVideo->file->getBasename(),
                $bestMatch['score'],
                $orphanVideo->contentIdentifier ?? 'none',
                $targetVideo->contentIdentifier ?? 'none',
            ));

            $groups->remove($groupKey);
        }
    }

    /**
     * Collects videos that already belong to a valid still+video Live Photo pair.
     *
     * @param AssetGroupCollection $groups Available capture groups
     *
     * @return list<array{group: AssetGroup, item: AssetItem}> Candidate companion videos
     */
    private function collectValidCompanionCandidates(AssetGroupCollection $groups): array
    {
        $candidates = [];

        foreach ($groups as $group) {
            /** @var array<string, true> $stillContentIds */
            $stillContentIds = [];

            foreach ($group->getItems() as $item) {
                if (
                    ($item->contentIdentifier !== null)
                    && $this->mediaTypeClassifier->isLivePhotoStill($item->file)
                ) {
                    $stillContentIds[$item->contentIdentifier] = true;
                }
            }

            if ($stillContentIds === []) {
                continue;
            }

            foreach ($group->getItems() as $item) {
                if ($this->mediaTypeClassifier->isLivePhotoStill($item->file)) {
                    continue;
                }

                if (($item->contentIdentifier !== null) && isset($stillContentIds[$item->contentIdentifier])) {
                    $candidates[] = ['group' => $group, 'item' => $item];
                }
            }
        }

        return $candidates;
    }

    /**
     * Counts how many candidate comparisons the reconciliation step will attempt.
     *
     * @param list<array{groupKey: string, item: AssetItem}>  $orphanVideos        Orphan singleton video groups with their video item
     * @param list<array{group: AssetGroup, item: AssetItem}> $candidateCompanions Valid existing companion videos
     *
     * @return int Number of perceptual comparisons after cheap pre-filtering
     */
    private function countReconciliationComparisons(array $orphanVideos, array $candidateCompanions): int
    {
        $comparisons = 0;

        foreach ($orphanVideos as $orphanEntry) {
            foreach ($candidateCompanions as $candidate) {
                if ($this->isReconcilableCandidate($orphanEntry['groupKey'], $orphanEntry['item'], $candidate)) {
                    ++$comparisons;
                }
            }
        }

        return $comparisons;
    }

    /**
     * Collects the orphan singleton video groups that qualify for reconciliation.
     *
     * @param AssetGroupCollection $groups Available capture groups
     *
     * @return list<array{groupKey: string, item: AssetItem}> Orphan singleton video groups with their video item
     */
    private function collectOrphanVideos(AssetGroupCollection $groups): array
    {
        $orphans = [];

        foreach (array_keys($groups->asArray()) as $groupKey) {
            $group = $groups->get($groupKey);

            if (!$group instanceof AssetGroup) {
                continue;
            }

            $orphanVideo = $this->findOrphanVideo($group);

            if ($orphanVideo instanceof AssetItem) {
                $orphans[] = ['groupKey' => $groupKey, 'item' => $orphanVideo];
            }
        }

        return $orphans;
    }

    /**
     * Returns whether a companion candidate is worth an expensive perceptual comparison.
     *
     * The cross-directory match is intentional, but cheap guards still avoid obviously
     * unrelated videos by rejecting same-group candidates and videos with materially
     * different durations.
     *
     * @param string                                    $orphanGroupKey Group key of the orphan singleton video
     * @param AssetItem                                 $orphanVideo    Orphan singleton video
     * @param array{group: AssetGroup, item: AssetItem} $candidate      Existing valid companion candidate
     */
    private function isReconcilableCandidate(string $orphanGroupKey, AssetItem $orphanVideo, array $candidate): bool
    {
        if ($candidate['group']->groupKey === $orphanGroupKey) {
            return false;
        }

        return $this->haveComparableDurations($orphanVideo, $candidate['item']);
    }

    /**
     * Applies a cheap duration-based pre-filter before perceptual video comparison.
     *
     * This reconciliation path is intentionally conservative: it only compares videos
     * whose normalized durations are identical. Missing duration metadata therefore
     * blocks reconciliation instead of widening the expensive cross-directory search.
     */
    private function haveComparableDurations(AssetItem $videoA, AssetItem $videoB): bool
    {
        $durationA = $videoA->metadata?->getVideoDurationSeconds();
        $durationB = $videoB->metadata?->getVideoDurationSeconds();

        if (($durationA === null) || ($durationB === null)) {
            return false;
        }

        return round($durationA, 3) === round($durationB, 3);
    }

    /**
     * Returns the singleton video item of a standalone orphan video group, or null.
     *
     * Only singleton video groups with a content identifier qualify. Still images and groups with
     * multiple items are handled by the normal grouping and Live Photo pairing flow.
     *
     * @param AssetGroup $group Group under inspection
     *
     * @return AssetItem|null Orphan video candidate, or null when the group is not eligible
     */
    private function findOrphanVideo(AssetGroup $group): ?AssetItem
    {
        if ($group->itemCount() !== 1) {
            return null;
        }

        $item = $group->getItems()[0];

        if ($this->mediaTypeClassifier->isLivePhotoStill($item->file)) {
            return null;
        }

        return $item->contentIdentifier !== null ? $item : null;
    }
}
