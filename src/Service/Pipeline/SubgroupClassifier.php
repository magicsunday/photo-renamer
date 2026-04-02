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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use Override;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_keys;
use function round;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * AssetGroup-native facade over HashSubGroupingService.
 *
 * Bridges the new AssetGroup/AssetItem model to the existing hash-based sub-grouping
 * logic. For each group with multiple items, constructs temporary FileDuplicate/Rename
 * objects, delegates to HashSubGroupingService::apply(), and maps the mutated rename
 * targets back to clusterIds on AssetItems.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SubgroupClassifier implements SubgroupClassifierInterface
{
    /**
     * @param HashSubGroupingServiceInterface   $hashSubGroupingService   Existing hash-based sub-grouping service
     * @param MediaTypeClassifierInterface      $mediaTypeClassifier      Classifies files as still or video
     * @param PerceptualHashCalculatorInterface $perceptualHashCalculator Multi-frame similarity scoring for orphan video reconciliation
     * @param SymfonyStyle                      $io                       Console output for progress feedback
     */
    public function __construct(
        private HashSubGroupingServiceInterface $hashSubGroupingService,
        private MediaTypeClassifierInterface $mediaTypeClassifier,
        private PerceptualHashCalculatorInterface $perceptualHashCalculator,
        private SymfonyStyle $io,
    ) {
    }

    /**
     * Classify items within each group by content identity / perceptual similarity.
     * Sets clusterId on each affected AssetItem.
     * Does NOT assign roles or compute names.
     *
     * @param AssetGroupCollection $groups The groups to classify
     */
    #[Override]
    public function classify(
        AssetGroupCollection $groups,
    ): void {
        $candidateCompanions = $this->collectValidCompanionCandidates($groups);
        $orphanVideos        = $this->collectOrphanVideos($groups);

        if (($candidateCompanions !== []) && ($orphanVideos !== [])) {
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

        $groupsToClassify = 0;

        foreach ($groups as $group) {
            if ($group->itemCount() > 1) {
                ++$groupsToClassify;
            } else {
                $group->markClassificationSucceeded();
            }
        }

        if ($groupsToClassify === 0) {
            return;
        }

        $this->io->newLine();
        $this->io->text('<fg=cyan>Classifying subgroups</>');

        $progressBar = $this->io->createProgressBar($groupsToClassify);
        $progressBar->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar->start();

        foreach ($groups as $group) {
            if ($group->itemCount() <= 1) {
                continue;
            }

            try {
                $this->classifyGroup($group);
            } finally {
                $this->hashSubGroupingService->clearCache();
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();
    }

    /**
     * Merges standalone video-only groups into existing Live Photo groups when the
     * video is perceptually identical to an already valid companion video.
     *
     * This covers cases where a MOV carries a wrong content identifier and was
     * therefore grouped separately even though another MOV already forms the valid
     * still+video pair. After merging, the orphan MOV can later be marked as a
     * duplicate inside the correct group.
     *
     * @param AssetGroupCollection                            $groups              Groups discovered by CaptureGroupBuilder
     * @param list<array{group: AssetGroup, item: AssetItem}> $candidateCompanions Valid existing companion videos
     * @param list<array{groupKey: string, item: AssetItem}>  $orphanVideos        Orphan singleton video groups with their video item
     * @param ProgressBar|null                                $progressBar         Optional progress bar for CLI feedback
     */
    private function mergeOrphanVideoDuplicatesIntoLivePhotoGroups(
        AssetGroupCollection $groups,
        array $candidateCompanions,
        array $orphanVideos,
        ?ProgressBar $progressBar = null,
    ): void {
        foreach ($orphanVideos as $orphanEntry) {
            $groupKey = $orphanEntry['groupKey'];
            $group    = $groups->get($groupKey);

            if (!$group instanceof AssetGroup) {
                continue;
            }

            $orphanVideo = $orphanEntry['item'];

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

                $progressBar?->advance();
            }

            $this->perceptualHashCalculator->clearCache();

            if (!is_array($bestMatch)) {
                continue;
            }

            /** @var AssetGroup $targetGroup */
            $targetGroup = $bestMatch['group'];
            /** @var AssetItem $targetVideo */
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

    /**
     * Classifies a single group by bridging to HashSubGroupingService.
     *
     * Atomic per group: clusterId assignments are collected in a temporary map and
     * applied to items only after the entire mapping step succeeds. If any step throws,
     * no items are modified and the group is marked as classification-degraded.
     *
     * @param AssetGroup $group The group to classify
     */
    private function classifyGroup(AssetGroup $group): void
    {
        try {
            $fileDuplicate = new FileDuplicate();

            /** @var array<string, AssetItem> $pathToItem */
            $pathToItem = [];

            /** @var array<string, Rename> $pathToRename */
            $pathToRename = [];

            foreach ($group->getItems() as $item) {
                $sourceFile = $item->file;
                $targetPath = $sourceFile->getPath() . DIRECTORY_SEPARATOR
                    . $group->groupKey . '.' . FileHelper::normalizeExtension($sourceFile->getExtension());

                $rename = new Rename($sourceFile, new SplFileInfo($targetPath));

                $fileDuplicate->addFile($sourceFile);
                $fileDuplicate->addRename($rename);
                $pathToItem[$sourceFile->getPathname()]   = $item;
                $pathToRename[$sourceFile->getPathname()] = $rename;
            }

            // Set canonical target from first rename (HashSubGroupingService reads this)
            $firstRename = $fileDuplicate->getRenames()->get(0);

            if ($firstRename instanceof Rename) {
                $fileDuplicate->setTarget($firstRename->getTarget());
            }

            // Build content identifier and temporal metadata maps from AssetItems
            /** @var array<string, string> $contentIdMap */
            $contentIdMap = [];

            /** @var array<string, TemporalMetadata|null> $temporalMetaMap */
            $temporalMetaMap = [];

            foreach ($group->getItems() as $item) {
                $path = $item->file->getPathname();

                if ($item->contentIdentifier !== null) {
                    $contentIdMap[$path] = $item->contentIdentifier;
                }

                if ($item->metadata !== null) {
                    $temporalMetaMap[$path] = $item->metadata;
                }
            }

            // Detect likely canonical and companion for Live Photo groups.
            // This is needed before role assignment so HashSubGroupingService can
            // properly exclude companion media types from content-hash sub-grouping.
            [$canonicalRename, $companionRename] = $this->detectLikelyCanonicalAndCompanion(
                $group,
                $pathToRename,
                $contentIdMap,
            );

            $targetPathnameResolver = (static fn (SplFileInfo $source, string $targetFilename): string => $source->getPath() . DIRECTORY_SEPARATOR . $targetFilename);

            $clusterMap = $this->hashSubGroupingService->apply(
                $fileDuplicate,
                $canonicalRename,
                $companionRename,
                $contentIdMap,
                $targetPathnameResolver,
                $temporalMetaMap,
            );

            if ($clusterMap === null) {
                // Single hash group or single file — no subgrouping needed
                $group->markClassificationSucceeded();

                return;
            }

            // Assign clusterIds directly from hash-based cluster membership (Regel 1:
            // cluster formation is filename-free). The cluster map keys are source
            // pathnames, values are merged hash group root keys.
            /**
             * Temporary map for cluster assignments, ensuring atomic updates to the
             * items in the AssetGroup only after all items have been successfully
             * categorized.
             *
             * @var array<string, array{clusterId: string, clusterRank: int}>
             */
            $clusterIdAssignments = [];

            /**
             * Counters for assigning stable numeric ranks within each detected cluster.
             *
             * @var array<string, int>
             */
            $clusterRankCounters = [];

            foreach ($group->getItems() as $item) {
                $sourcePath = $item->file->getPathname();
                $clusterKey = $clusterMap[$sourcePath] ?? null;

                if ($clusterKey === null) {
                    continue;
                }

                $rank                             = $clusterRankCounters[$clusterKey] ?? 0;
                $clusterRankCounters[$clusterKey] = $rank + 1;

                $clusterIdAssignments[$sourcePath] = [
                    'clusterId'   => $clusterKey,
                    'clusterRank' => $rank,
                ];
            }

            // All assignments computed successfully — apply them atomically
            foreach ($clusterIdAssignments as $sourcePath => $assignment) {
                $item = $pathToItem[$sourcePath] ?? null;

                if ($item === null) {
                    continue;
                }

                $group->replaceItem(
                    $item,
                    $item->withClusterId($assignment['clusterId'])->withClusterRank($assignment['clusterRank']),
                );
            }

            $group->markClassificationSucceeded();
        } catch (Throwable $exception) {
            // Atomic guarantee: no partial clusterIds were applied because assignments
            // are collected in a temporary map and only applied after full success.
            $group->markClassificationFailed($exception->getMessage());
            $group->addDecision(sprintf('Subgroup classification failed: %s', $exception->getMessage()));
        }
    }

    /**
     * Detects likely canonical and companion renames for Live Photo groups.
     *
     * Before role assignment, identifies the first still with a content identifier
     * as the likely canonical and the first video with a matching content identifier
     * as the likely companion. This allows HashSubGroupingService to properly exclude
     * companion media types from content-hash sub-grouping.
     *
     * @param AssetGroup            $group        The group to analyze
     * @param array<string, Rename> $pathToRename Map from source pathname to Rename
     * @param array<string, string> $contentIdMap Map from source pathname to content identifier
     *
     * @return array{0: Rename|null, 1: Rename|null} [canonicalRename, companionRename]
     */
    private function detectLikelyCanonicalAndCompanion(
        AssetGroup $group,
        array $pathToRename,
        array $contentIdMap,
    ): array {
        // Find the first still with a content identifier
        $likelyCanonical    = null;
        $canonicalContentId = null;

        foreach ($group->getItems() as $item) {
            if ($item->contentIdentifier === null) {
                continue;
            }

            if (!$this->mediaTypeClassifier->isLivePhotoStill($item->file)) {
                continue;
            }

            $likelyCanonical    = $pathToRename[$item->file->getPathname()] ?? null;
            $canonicalContentId = $item->contentIdentifier;

            break;
        }

        if ($likelyCanonical === null || $canonicalContentId === null) {
            return [null, null];
        }

        // Find the first video with the same content identifier
        $likelyCompanion = null;

        foreach ($group->getItems() as $item) {
            if ($this->mediaTypeClassifier->isLivePhotoStill($item->file)) {
                continue;
            }

            $itemContentId = $contentIdMap[$item->file->getPathname()] ?? null;

            if ($itemContentId === $canonicalContentId) {
                $likelyCompanion = $pathToRename[$item->file->getPathname()] ?? null;

                break;
            }
        }

        return [$likelyCanonical, $likelyCompanion];
    }
}
