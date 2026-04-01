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
use Override;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

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
     * @param HashSubGroupingServiceInterface $hashSubGroupingService Existing hash-based sub-grouping service
     * @param MediaTypeClassifierInterface    $mediaTypeClassifier    Classifies files as still or video
     * @param SymfonyStyle                    $io                     Console output for progress feedback
     */
    public function __construct(
        private HashSubGroupingServiceInterface $hashSubGroupingService,
        private MediaTypeClassifierInterface $mediaTypeClassifier,
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
        $this->io->newLine(3);
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

            $applied = $this->hashSubGroupingService->apply(
                $fileDuplicate,
                $canonicalRename,
                $companionRename,
                $contentIdMap,
                $targetPathnameResolver,
                $temporalMetaMap,
            );

            if (!$applied) {
                // Single hash group or single file — no subgrouping needed
                $group->markClassificationSucceeded();

                return;
            }

            // Collect all clusterId and clusterRank assignments in a temporary map before
            // applying any. This ensures atomicity: if the mapping step throws, no items
            // are modified.
            /** @var array<string, array{clusterId: string, clusterRank: int}> $clusterIdAssignments */
            $clusterIdAssignments = [];

            /** @var array<string, int> $clusterRankCounters */
            $clusterRankCounters = [];

            foreach ($fileDuplicate->getRenames() as $rename) {
                $sourcePath = $rename->getSource()->getPathname();
                $item       = $pathToItem[$sourcePath] ?? null;

                if ($item === null) {
                    continue;
                }

                $targetBasename = FileHelper::basenameWithoutExtension($rename->getTarget());
                $clusterBase    = FileHelper::stripDuplicateSuffix($targetBasename);

                $rank                              = $clusterRankCounters[$clusterBase] ?? 0;
                $clusterRankCounters[$clusterBase] = $rank + 1;

                $clusterIdAssignments[$sourcePath] = [
                    'clusterId'   => $targetBasename,
                    'clusterRank' => $rank,
                ];
            }

            // All assignments computed successfully — apply them atomically
            foreach ($clusterIdAssignments as $sourcePath => $assignment) {
                $item = $pathToItem[$sourcePath];
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
