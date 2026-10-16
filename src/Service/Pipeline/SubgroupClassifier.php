<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Helper\AnalysisMemoryBudget;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\MergeDecision;
use MagicSunday\Renamer\Service\ContentClassifierInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use RuntimeException;
use SplFileInfo;

use function sprintf;

/**
 * Applies shared content membership directly to AssetItems. Real source files,
 * metadata and actual companion hints cross the analysis boundary; no synthetic
 * rename targets or legacy naming models are constructed. Assignment remains
 * atomic per group and evidence reporting remains bounded.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SubgroupClassifier
{
    /**
     * @param ContentClassifierInterface     $contentClassifier     Shared source-only content and evidence rules
     * @param MediaTypeClassifier            $mediaTypeClassifier   Classifies files as Live Photo stills or videos for companion bridging
     * @param OrphanLivePhotoVideoReconciler $orphanVideoReconciler Specialized pre-pass for orphan MOV reconciliation
     * @param ProgressReporterInterface      $progressReporter      Narrow reporting boundary for progress headings and diagnostics
     */
    public function __construct(
        private ContentClassifierInterface $contentClassifier,
        private MediaTypeClassifier $mediaTypeClassifier,
        private OrphanLivePhotoVideoReconciler $orphanVideoReconciler,
        private ProgressReporterInterface $progressReporter,
    ) {
    }

    /**
     * Classify items within each group by content identity / perceptual similarity.
     * Sets clusterId on each affected AssetItem.
     * Does NOT assign roles or compute names.
     *
     * @param AssetGroupCollection $groups The groups to classify
     */
    public function classify(
        AssetGroupCollection $groups,
    ): void {
        $this->orphanVideoReconciler->reconcile($groups);

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

        $this->progressReporter->section('<fg=cyan>Classifying subgroups</>');
        $this->progressReporter->startProgress($groupsToClassify);

        foreach ($groups as $group) {
            if ($group->itemCount() <= 1) {
                continue;
            }

            try {
                $this->classifyGroup($group);
            } finally {
                $this->contentClassifier->clearCache();
            }

            $this->progressReporter->advance();
        }

        $this->progressReporter->finish();
    }

    /**
     * Classifies actual sources through the shared content boundary.
     *
     * Atomic per group: clusterId assignments are collected in a temporary map and
     * applied to items only after the entire mapping step succeeds. Runtime failures
     * mark the group as classification-degraded before any cluster assignments are
     * applied. Logic exceptions and PHP errors propagate.
     *
     * @param AssetGroup $group The group to classify
     */
    private function classifyGroup(AssetGroup $group): void
    {
        try {
            $files           = [];
            $pathToItem      = [];
            $contentIdMap    = [];
            $temporalMetaMap = [];

            foreach ($group->getItems() as $item) {
                AnalysisMemoryBudget::assertHeadroom('subgroup source views');
                $file              = $item->getFile();
                $path              = $file->getPathname();
                $files[]           = $file;
                $pathToItem[$path] = $item;

                if ($item->contentIdentifier !== null) {
                    $contentIdMap[$path] = $item->contentIdentifier;
                }

                if ($item->metadata !== null) {
                    $temporalMetaMap[$path] = $item->metadata;
                }
            }

            [$canonical, $companion] = $this->detectLikelyCanonicalAndCompanion($group, $contentIdMap);

            // Keep one example and a count per finite reason, not every pair.
            // Typed decisions remain available to service-level observers.
            /** @var array<string, int> $decisionCounts */
            $decisionCounts = [];
            /** @var array<string, MergeDecision> $decisionExamples */
            $decisionExamples = [];
            $onDecision       = static function (MergeDecision $decision) use (&$decisionCounts, &$decisionExamples): void {
                $key                  = $decision->reason->value;
                $decisionCounts[$key] = ($decisionCounts[$key] ?? 0) + 1;
                $decisionExamples[$key] ??= $decision;
            };

            $classification = $this->contentClassifier->classify($files, $canonical, $companion, $contentIdMap, $temporalMetaMap, $onDecision);
            $clusterMap     = $classification->clusterMap();

            foreach ($decisionExamples as $key => $decision) {
                $group->addDecision(sprintf('%s (%d comparison(s))', $decision->describe(), $decisionCounts[$key]));
            }

            if (!$classification->hasSubgroups()) {
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
                $sourcePath = $item->pathname;
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
        } catch (RuntimeException $exception) {
            // Atomic guarantee: no partial clusterIds were applied because assignments
            // are collected in a temporary map and only applied after full success.
            // Runtime analysis failures degrade this group. Logic exceptions and PHP
            // errors deliberately propagate rather than masquerading as media failures.
            $group->markClassificationFailed($exception->getMessage());
            $group->addDecision(sprintf('Subgroup classification failed: %s', $exception->getMessage()));
        }
    }

    /**
     * Detects likely preferred still and companion sources for Live Photo groups.
     *
     * Before role assignment, identifies the first still with a content identifier
     * as the likely canonical and the first video with a matching content identifier
     * as the likely companion. This allows the shared content kernel to properly exclude
     * companion media types from content-hash sub-grouping.
     *
     * @param AssetGroup            $group        The group to analyze
     * @param array<string, string> $contentIdMap Map from source pathname to content identifier
     *
     * @return array{0: SplFileInfo|null, 1: SplFileInfo|null} Preferred source and actual paired companion
     */
    private function detectLikelyCanonicalAndCompanion(
        AssetGroup $group,
        array $contentIdMap,
    ): array {
        // Find the first still with a content identifier
        $likelyCanonical    = null;
        $canonicalContentId = null;

        foreach ($group->getItems() as $item) {
            if ($item->contentIdentifier === null) {
                continue;
            }

            if (!$this->mediaTypeClassifier->isLivePhotoStill($item->getFile())) {
                continue;
            }

            $likelyCanonical    = $item->getFile();
            $canonicalContentId = $item->contentIdentifier;

            break;
        }

        if ($likelyCanonical === null || $canonicalContentId === null) {
            return [null, null];
        }

        // Find the first video with the same content identifier
        $likelyCompanion = null;

        foreach ($group->getItems() as $item) {
            if ($this->mediaTypeClassifier->isLivePhotoStill($item->getFile())) {
                continue;
            }

            $itemContentId = $contentIdMap[$item->pathname] ?? null;

            if ($itemContentId === $canonicalContentId) {
                $likelyCompanion = $item->getFile();

                break;
            }
        }

        return [$likelyCanonical, $likelyCompanion];
    }
}
