<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use Override;

use function count;
use function sprintf;

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
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Classifies files as still or video
     */
    public function __construct(
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
    }

    /**
     * Detect Live Photo companions for the given canonical item within the group.
     *
     * @param AssetGroup $group     Group containing candidate companion items
     * @param AssetItem  $canonical Canonical item whose companions are sought
     *
     * @return array<string, true> Pathnames of detected companion items
     */
    #[Override]
    public function detect(AssetGroup $group, AssetItem $canonical): array
    {
        // Safety: canonical must have a content identifier for any companion detection
        if ($canonical->contentIdentifier === null) {
            return [];
        }

        $canonicalIsStill  = $this->mediaTypeClassifier->isLivePhotoStill($canonical->file);
        $canonicalBasename = FileHelper::basenameWithoutExtension($canonical->file);

        /** @var array<string, true> $companions */
        $companions = [];

        // Phase 1: Content-ID matching (highest priority)
        foreach ($group->getItems() as $item) {
            if ($item === $canonical) {
                continue;
            }

            // Only different media types can be companions
            $itemIsStill = $this->mediaTypeClassifier->isLivePhotoStill($item->file);

            if ($canonicalIsStill === $itemIsStill) {
                continue;
            }

            if ($item->contentIdentifier === $canonical->contentIdentifier) {
                $companions[$item->file->getPathname()] = true;
            }
        }

        // Phase 2: Basename fallback (only when no content-ID companions found)
        if ($companions === []) {
            /** @var list<AssetItem> $fallbackCandidates */
            $fallbackCandidates = [];

            foreach ($group->getItems() as $item) {
                if ($item === $canonical) {
                    continue;
                }

                $itemIsStill = $this->mediaTypeClassifier->isLivePhotoStill($item->file);

                if ($canonicalIsStill === $itemIsStill) {
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

                    return [];
                }

                $companions[$candidate->file->getPathname()] = true;
            }
        }

        return $companions;
    }
}
