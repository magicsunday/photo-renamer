<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;

use function count;

/**
 * Detects the companion rename for a Live Photo duplicate group in the legacy pipeline.
 *
 * The legacy duplicate path needs a conservative companion lookup:
 * - prefer exact content-identifier matches across different media types
 * - preserve idempotent source names that already match the canonical target basename
 * - fall back to a single opposite-media candidate when the companion lacks a content ID
 * - refuse the fallback when that single candidate exposes a conflicting non-null content ID
 *
 * This detector isolates that Live Photo-specific decision tree from the broader
 * duplicate grouping and suffix assignment orchestration.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyLivePhotoCompanionDetector
{
    /**
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Classifier used to distinguish stills from companion videos.
     */
    public function __construct(private MediaTypeClassifierInterface $mediaTypeClassifier)
    {
    }

    /**
     * Identifies the companion rename within a duplicate group.
     *
     * Uses the content identifier map to find a file that shares the canonical's
     * Live Photo content ID but has a different media type (e.g. MOV for a JPG canonical).
     *
     * When no exact content-ID match is found (e.g. the MOV companion lacks a content
     * identifier in its metadata), falls back to the first file of a different media
     * type. This ensures video companions are excluded from hash sub-grouping even
     * when only the still image carries the Live Photo content identifier.
     *
     * If there is exactly one fallback candidate and it exposes a conflicting
     * non-null content identifier, both files are marked as conflict paths and no
     * companion is returned.
     *
     * @param Rename|null           $canonicalRename        Canonical rename of the current duplicate group.
     * @param FileDuplicate         $fileDuplicate          Group whose renames are searched for a companion.
     * @param array<string, string> $contentIdentifierMap   Normalized content identifiers keyed by source pathname.
     * @param array<string, true>   $livePhotoConflictFiles Conflict map that is updated when fallback detection finds mismatched IDs.
     *
     * @return Rename|null The detected companion rename, or null if no companion was found.
     */
    public function detect(
        ?Rename $canonicalRename,
        FileDuplicate $fileDuplicate,
        array $contentIdentifierMap,
        array &$livePhotoConflictFiles,
    ): ?Rename {
        if (!$canonicalRename instanceof Rename) {
            return null;
        }

        $canonicalPath      = $canonicalRename->getSource()->getPathname();
        $canonicalContentId = $contentIdentifierMap[$canonicalPath] ?? null;

        if ($canonicalContentId === null) {
            return null;
        }

        $canonicalIsStill = $this->mediaTypeClassifier->isLivePhotoStill($canonicalRename->getSource());

        $canonicalTargetBasename = FileHelper::basenameWithoutExtension($canonicalRename->getTarget());

        /** @var Rename|null $contentIdCompanion */
        $contentIdCompanion = null;

        /** @var Rename|null $fallbackCompanion */
        $fallbackCompanion = null;

        /** @var list<Rename> $fallbackCandidates */
        $fallbackCandidates = [];

        foreach ($fileDuplicate->getRenames() as $rename) {
            if ($rename === $canonicalRename) {
                continue;
            }

            $renameIsStill = $this->mediaTypeClassifier->isLivePhotoStill($rename->getSource());

            // Only consider files of a different media type as companions.
            if ($canonicalIsStill === $renameIsStill) {
                continue;
            }

            $renamePath      = $rename->getSource()->getPathname();
            $renameContentId = $contentIdentifierMap[$renamePath] ?? null;

            if ($renameContentId === $canonicalContentId) {
                $renameBasename = FileHelper::basenameWithoutExtension($rename->getSource());

                // Idempotency: prefer the companion whose source name already matches
                // the canonical target (file is already correctly named).
                if ($renameBasename === $canonicalTargetBasename) {
                    return $rename;
                }

                // Track first content-ID match as candidate.
                $contentIdCompanion ??= $rename;

                continue;
            }

            // Track the first different-media-type file as a fallback companion.
            $fallbackCandidates[] = $rename;
            $fallbackCompanion ??= $rename;
        }

        if ($contentIdCompanion instanceof Rename) {
            return $contentIdCompanion;
        }

        if (
            ($fallbackCompanion instanceof Rename)
            && (count($fallbackCandidates) === 1)
        ) {
            $fallbackPath      = $fallbackCompanion->getSource()->getPathname();
            $fallbackContentId = $contentIdentifierMap[$fallbackPath] ?? null;

            if (
                ($fallbackContentId !== null)
                && ($fallbackContentId !== $canonicalContentId)
            ) {
                $livePhotoConflictFiles[$canonicalPath] = true;
                $livePhotoConflictFiles[$fallbackPath]  = true;

                return null;
            }
        }

        return $fallbackCompanion;
    }
}
