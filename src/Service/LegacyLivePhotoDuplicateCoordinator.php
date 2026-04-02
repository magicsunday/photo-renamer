<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;

/**
 * Coordinates Live Photo-specific duplicate handling inside the legacy pipeline.
 *
 * The legacy duplicate loop needs more than just a companion rename lookup. It
 * also needs a normalized still/companion pair for later one-way quality-flag
 * propagation. This coordinator keeps that small Live Photo workflow together
 * while delegating the actual companion detection to the dedicated detector.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyLivePhotoDuplicateCoordinator
{
    /**
     * @param LegacyLivePhotoCompanionDetector $livePhotoCompanionDetector Detects the matching companion rename inside one duplicate group.
     * @param MediaTypeClassifierInterface     $mediaTypeClassifier        Classifier used to normalize still/companion ordering in returned pairs.
     */
    public function __construct(
        private LegacyLivePhotoCompanionDetector $livePhotoCompanionDetector,
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
    }

    /**
     * Coordinates companion detection and normalized pair recording for one group.
     *
     * @param Rename|null           $canonicalRename        Canonical rename chosen for the duplicate group.
     * @param FileDuplicate         $fileDuplicate          Duplicate group being processed.
     * @param array<string, string> $contentIdentifierMap   Normalized content identifiers keyed by source pathname.
     * @param array<string, true>   $livePhotoConflictFiles Conflict map updated when fallback detection hits mismatched content IDs.
     *
     * @return LegacyLivePhotoDuplicateCoordination Result carrying the companion rename and optional normalized pair.
     */
    public function coordinate(
        ?Rename $canonicalRename,
        FileDuplicate $fileDuplicate,
        array $contentIdentifierMap,
        array &$livePhotoConflictFiles,
    ): LegacyLivePhotoDuplicateCoordination {
        $companionRename = $this->livePhotoCompanionDetector->detect(
            $canonicalRename,
            $fileDuplicate,
            $contentIdentifierMap,
            $livePhotoConflictFiles,
        );

        if (
            !($canonicalRename instanceof Rename)
            || !($companionRename instanceof Rename)
        ) {
            return new LegacyLivePhotoDuplicateCoordination($companionRename, null);
        }

        $canonicalPath    = $canonicalRename->getSource()->getPathname();
        $companionPath    = $companionRename->getSource()->getPathname();
        $canonicalIsStill = $this->mediaTypeClassifier->isLivePhotoStill($canonicalRename->getSource());

        if ($canonicalIsStill) {
            return new LegacyLivePhotoDuplicateCoordination(
                $companionRename,
                new LegacyLivePhotoPair($canonicalPath, $companionPath),
            );
        }

        return new LegacyLivePhotoDuplicateCoordination(
            $companionRename,
            new LegacyLivePhotoPair($companionPath, $canonicalPath),
        );
    }
}
