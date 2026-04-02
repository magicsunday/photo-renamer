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
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use Override;

use function array_keys;
use function implode;
use function sprintf;

/**
 * Thin orchestrator that assigns roles (Canonical, Duplicate, Companion) to items
 * within each AssetGroup by delegating scoring to CanonicalScorer and companion
 * detection to CompanionDetector. Quality flags are propagated from canonical stills
 * to their companions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RoleAssigner implements RoleAssignerInterface
{
    /**
     * @param CanonicalScorerInterface   $scorer                   Scores items and selects the canonical
     * @param CompanionDetectorInterface $companionDetector        Detects Live Photo companions
     * @param MediaCompatibilityPolicy   $mediaCompatibilityPolicy Shared still/video compatibility rules
     */
    public function __construct(
        private CanonicalScorerInterface $scorer,
        private CompanionDetectorInterface $companionDetector,
        private MediaCompatibilityPolicy $mediaCompatibilityPolicy,
    ) {
    }

    /**
     * Assign roles (Canonical, Duplicate, Companion, Ambiguous) to items in each group.
     * Uses CanonicalScorer for selection and CompanionDetector for Live Photo pairing.
     *
     * @param AssetGroupCollection $groups  Groups whose items will receive roles
     * @param PipelineContext      $context Mutable pipeline state for quality flag propagation
     */
    #[Override]
    public function assign(
        AssetGroupCollection $groups,
        PipelineContext $context,
    ): void {
        foreach ($groups as $group) {
            $this->assignGroup($group, $context);
        }
    }

    /**
     * Assigns roles within a single AssetGroup.
     *
     * @param AssetGroup      $group   Group whose items will receive roles
     * @param PipelineContext $context Mutable pipeline state for quality flag propagation
     */
    private function assignGroup(AssetGroup $group, PipelineContext $context): void
    {
        if ($group->itemCount() <= 1) {
            return;
        }

        // Note degraded classification in the decision log so downstream consumers
        // are aware that subgroup data may be missing for this group.
        if ($group->isClassificationDegraded()) {
            $group->addDecision(sprintf(
                'Role assignment proceeding with degraded classification: %s',
                $group->getClassificationFailureReason() ?? 'unknown reason',
            ));
        }

        // 1. Score all items
        $this->scorer->scoreItems($group);

        // 2. Select canonical (highest score)
        $canonical = $this->scorer->selectCanonical($group);

        if (!$canonical instanceof AssetItem) {
            return;
        }

        // Log the canonical decision
        $group->addDecision(sprintf(
            'Canonical: %s (score %d: %s)',
            $canonical->file->getBasename(),
            $canonical->priorityScore,
            implode(', ', $canonical->reasoning),
        ));

        // 3. Detect companions for the canonical
        $companionPaths = $this->companionDetector->detect($group, $canonical);

        // 4. Detect companions for non-canonical stills with content identifiers.
        //    In multi-subgroup groups, each still may have its own LP video companion
        //    (e.g. B.jpg + B.mov in a different subgroup from the canonical).
        $subgroupCompanionPaths = $this->detectSubgroupCompanions($group, $canonical);
        $allCompanionPaths      = $companionPaths + $subgroupCompanionPaths;

        // 5. Assign roles to all non-canonical items
        foreach ($group->getItems() as $item) {
            if ($item->file->getPathname() === $canonical->file->getPathname()) {
                continue;
            }

            if (isset($allCompanionPaths[$item->file->getPathname()])) {
                $group->replaceItem($item, $item->withRole(ItemRole::Companion));
                $group->addDecision(sprintf(
                    'Companion: %s (Live Photo pair)',
                    $item->file->getBasename(),
                ));

                continue;
            }

            // Duplicates are further differentiated by their clusterId (set by SubgroupClassifier in the previous pipeline phase)
            $group->replaceItem($item, $item->withRole(ItemRole::Duplicate));
        }

        // 6. Propagate quality flags from still to companion
        $this->propagateQualityFlags($canonical, $companionPaths, $context);

        // 7. Detect cross-directory companion pairs
        $this->detectCrossDirectoryCompanions($group, $canonical, $allCompanionPaths, $context);
    }

    /**
     * Detects Live Photo companions for non-canonical stills with content identifiers.
     *
     * In multi-subgroup groups (e.g. original LP + edited LP), each non-canonical still
     * may have its own video companion. This method finds those relationships by calling
     * the CompanionDetector for each non-canonical still that has a content identifier
     * different from the canonical's.
     *
     * Only one companion is detected per content identifier to avoid ambiguity.
     *
     * @param AssetGroup $group     Group containing items to analyze
     * @param AssetItem  $canonical The canonical item (already handled separately)
     *
     * @return array<string, true> Additional companion pathnames
     */
    private function detectSubgroupCompanions(AssetGroup $group, AssetItem $canonical): array
    {
        /** @var array<string, true> $companions */
        $companions = [];

        /** @var array<string, true> $seenContentIds */
        $seenContentIds = [];

        if ($canonical->contentIdentifier !== null) {
            $seenContentIds[$canonical->contentIdentifier] = true;
        }

        foreach ($group->getItems() as $item) {
            if ($item->file->getPathname() === $canonical->file->getPathname()) {
                continue;
            }

            if ($item->contentIdentifier === null) {
                continue;
            }

            // Skip content IDs already handled (canonical's or previously seen)
            if (isset($seenContentIds[$item->contentIdentifier])) {
                continue;
            }

            $seenContentIds[$item->contentIdentifier] = true;

            // Only stills can anchor a companion pair
            $detected = $this->companionDetector->detect($group, $item);
            $companions += $detected;
        }

        return $companions;
    }

    /**
     * Propagates quality flags (fallback date, ambiguous timezone) from the canonical
     * item to its companions. Fallback date is only propagated when the canonical is
     * a still image (not video), while ambiguous timezone is propagated unconditionally.
     *
     * @param AssetItem           $canonical      The canonical item
     * @param array<string, true> $companionPaths Pathnames of detected companions
     * @param PipelineContext     $context        Mutable pipeline state
     */
    private function propagateQualityFlags(
        AssetItem $canonical,
        array $companionPaths,
        PipelineContext $context,
    ): void {
        if ($companionPaths === []) {
            return;
        }

        $canonicalPath    = $canonical->file->getPathname();
        $canonicalIsStill = $this->mediaCompatibilityPolicy->isStillImage($canonical->file);

        $hasFallbackDate      = $canonicalIsStill && isset($context->getFallbackDateFiles()[$canonicalPath]);
        $hasAmbiguousTimezone = isset($context->getAmbiguousTimezoneFiles()[$canonicalPath]);

        foreach (array_keys($companionPaths) as $companionPath) {
            if ($hasFallbackDate) {
                $context->addFallbackDateFile($companionPath);
            }

            if ($hasAmbiguousTimezone) {
                $context->addAmbiguousTimezoneFile($companionPath);
            }
        }
    }

    /**
     * Detects Live Photo companions that are in a different directory than their canonical.
     *
     * @param AssetGroup          $group          Group with assigned roles
     * @param AssetItem           $canonical      The canonical item
     * @param array<string, true> $companionPaths Pathnames of all detected companions
     * @param PipelineContext     $context        Mutable pipeline state
     */
    private function detectCrossDirectoryCompanions(
        AssetGroup $group,
        AssetItem $canonical,
        array $companionPaths,
        PipelineContext $context,
    ): void {
        if ($companionPaths === []) {
            return;
        }

        $canonicalDir = $canonical->file->getPath();

        foreach ($group->getItems() as $item) {
            if (!isset($companionPaths[$item->file->getPathname()])) {
                continue;
            }

            if ($item->file->getPath() !== $canonicalDir) {
                $context->addCrossDirectoryCompanion(
                    $canonical->file->getPathname(),
                    $item->file->getPathname(),
                );
            }
        }
    }
}
