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
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use Override;

use function count;
use function sprintf;
use function str_contains;
use function strlen;
use function usort;

/**
 * Detects Live Photo companions for a given canonical item within an asset group.
 *
 * Uses content-ID matching (highest priority) with basename fallback when the candidate
 * lacks a content ID. Enforces safety guards: canonical must have a content identifier,
 * only different media types can pair, exactly one basename fallback candidate is required,
 * and conflicting content IDs are logged rather than paired.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CompanionDetector implements CompanionDetectorInterface
{
    /**
     * @param MediaCompatibilityPolicy $mediaCompatibilityPolicy Shared still/video compatibility rules
     */
    public function __construct(
        private MediaCompatibilityPolicy $mediaCompatibilityPolicy,
    ) {
    }

    /**
     * Detect Live Photo companions for the given canonical item within the group.
     *
     * @param AssetGroup $group     Group containing candidate companion items
     * @param AssetItem  $canonical Canonical item whose companions are sought
     *
     * @return CompanionPathSet Pathnames of detected companion items
     */
    #[Override]
    public function detect(AssetGroup $group, AssetItem $canonical): CompanionPathSet
    {
        // Safety: canonical must have a content identifier for any companion detection
        if ($canonical->contentIdentifier === null) {
            return new CompanionPathSet();
        }

        $canonicalBasename = FileHelper::basenameWithoutExtension($canonical->file);

        $companions = new CompanionPathSet();

        // Phase 1: Content-ID matching (highest priority)
        // Collect candidates grouped by media type, then select the best per type.
        /** @var array<string, list<AssetItem>> $candidatesByMediaType */
        $candidatesByMediaType = [];

        foreach ($group->getItems() as $item) {
            if ($item === $canonical) {
                continue;
            }

            // Only different media types can be companions
            if (!$this->mediaCompatibilityPolicy->areDifferentMediaFamilies($canonical->file, $item->file)) {
                continue;
            }

            if ($item->contentIdentifier === $canonical->contentIdentifier) {
                $mediaType = $this->mediaCompatibilityPolicy->isStillImage($item->file)
                    ? 'still'
                    : 'video';
                $candidatesByMediaType[$mediaType][] = $item;
            }
        }

        // Select best candidate per media type
        foreach ($candidatesByMediaType as $candidates) {
            if (count($candidates) === 1) {
                $companions->add($candidates[0]->file->getPathname());
            } else {
                $winner = $this->selectBestCandidate($candidates, $canonical);
                $companions->add($winner->file->getPathname());
            }
        }

        // Phase 2: Basename fallback (only when no content-ID companions found)
        if ($companions->isEmpty()) {
            /** @var list<AssetItem> $fallbackCandidates */
            $fallbackCandidates = [];

            foreach ($group->getItems() as $item) {
                if ($item === $canonical) {
                    continue;
                }

                if (!$this->mediaCompatibilityPolicy->areDifferentMediaFamilies($canonical->file, $item->file)) {
                    continue;
                }

                $itemBasename = FileHelper::basenameWithoutExtension($item->file);

                if ($itemBasename === $canonicalBasename) {
                    $fallbackCandidates[] = $item;
                }
            }

            // Exactly one candidate required (0 or 2+ = ambiguous, skip)
            if (count($fallbackCandidates) === 1) {
                $candidate = $fallbackCandidates[0];

                // Conflicting content ID: candidate has a different non-null content ID
                if (
                    ($candidate->contentIdentifier !== null)
                    && ($candidate->contentIdentifier !== $canonical->contentIdentifier)
                ) {
                    $group->addDecision(
                        sprintf(
                            'LP conflict: canonical %s (content-id=%s) vs %s (content-id=%s)',
                            $canonical->file->getPathname(),
                            $canonical->contentIdentifier,
                            $candidate->file->getPathname(),
                            $candidate->contentIdentifier,
                        ),
                    );

                    return new CompanionPathSet();
                }

                $companions->add($candidate->file->getPathname());
            }
        }

        return $companions;
    }

    /**
     * Select the best companion candidate from a list of same-media-type candidates.
     *
     * Preference chain:
     * 1. Basename matches canonical (idempotent — file already has the correct companion name)
     * 2. Existing clean companion name (date-based pattern from a prior run, no -duplicate- suffix)
     * 3. Stable tie-breaker: clusterRank (lower wins), then shortest pathname, then lexicographic
     *
     * @param list<AssetItem> $candidates Same-media-type candidates (at least 2)
     * @param AssetItem       $canonical  The canonical item these candidates are pairing with
     *
     * @return AssetItem The winning candidate
     */
    private function selectBestCandidate(array $candidates, AssetItem $canonical): AssetItem
    {
        $canonicalBasename = FileHelper::basenameWithoutExtension($canonical->file);

        // Tier 1: Basename matches canonical (idempotent case — only when canonical
        // already has a clean name, meaning it won't be renamed with a different basename)
        if (!str_contains($canonicalBasename, Constants::DUPLICATE_IDENTIFIER)) {
            foreach ($candidates as $candidate) {
                if (FileHelper::basenameWithoutExtension($candidate->file) === $canonicalBasename) {
                    return $candidate;
                }
            }
        }

        // Tier 2: Existing clean companion name (date-based pattern, no -duplicate- suffix)
        $cleanCandidates = [];

        foreach ($candidates as $candidate) {
            if ($this->isCleanCompanionName($candidate)) {
                $cleanCandidates[] = $candidate;
            }
        }

        if (count($cleanCandidates) === 1) {
            return $cleanCandidates[0];
        }

        // Tier 3: Stable tie-breaker
        $remaining = $cleanCandidates !== [] ? $cleanCandidates : $candidates;

        usort($remaining, $this->compareTieBreaker(...));

        return $remaining[0];
    }

    /**
     * Returns true when the candidate has a date-based name without a -duplicate- suffix,
     * indicating it was previously named as a companion in an earlier run.
     */
    private function isCleanCompanionName(AssetItem $candidate): bool
    {
        if (!$candidate->matchesNamingPattern()) {
            return false;
        }

        $basename = FileHelper::basenameWithoutExtension($candidate->file);

        return !str_contains($basename, Constants::DUPLICATE_IDENTIFIER);
    }

    /**
     * Stable tie-breaker comparison: clusterRank (lower wins), then shorter pathname, then lexicographic.
     */
    private function compareTieBreaker(AssetItem $itemA, AssetItem $itemB): int
    {
        // clusterRank: lower wins (null sorts after non-null)
        $aRank = $itemA->clusterRank;
        $bRank = $itemB->clusterRank;

        if ($aRank !== null && $bRank !== null) {
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }
        } elseif ($aRank !== null) {
            return -1;
        } elseif ($bRank !== null) {
            return 1;
        }

        // Shorter pathname wins
        $aPath  = $itemA->file->getPathname();
        $bPath  = $itemB->file->getPathname();
        $lenCmp = strlen($aPath) <=> strlen($bPath);

        if ($lenCmp !== 0) {
            return $lenCmp;
        }

        // Lexicographic
        return $aPath <=> $bPath;
    }
}
