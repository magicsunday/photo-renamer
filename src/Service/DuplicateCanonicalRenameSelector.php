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

/**
 * Selects the canonical rename inside one legacy duplicate group.
 *
 * The legacy duplicate pipeline keeps a stable preference order that must stay
 * idempotent across re-runs:
 * 1. a source file that already has the canonical basename wins
 * 2. otherwise a file with a Live Photo content identifier wins
 * 3. otherwise the first qualifying rename remains canonical
 *
 * The selector also decides whether the canonical still needs explicit
 * promotion to the unsuffixed base name when another extension variant already
 * occupies that base name in the group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class DuplicateCanonicalRenameSelector
{
    /**
     * Selects the canonical rename and promotion flags for a duplicate group.
     *
     * Only renames that already point at the group's canonical target path, or
     * whose source basename already matches the canonical target basename, are
     * eligible. This preserves idempotent re-runs where the correct file may
     * already carry the clean basename in another directory.
     *
     * @param FileDuplicate         $fileDuplicate        Duplicate group whose renames should be inspected
     * @param array<string, string> $contentIdentifierMap Normalized Live Photo content identifiers keyed by source pathname
     *
     * @return DuplicateCanonicalSelection Selected canonical rename and promotion state
     */
    public function select(FileDuplicate $fileDuplicate, array $contentIdentifierMap): DuplicateCanonicalSelection
    {
        $renames = $fileDuplicate->getRenames();

        $canonicalTargetPath     = $fileDuplicate->getTarget()->getPathname();
        $canonicalTargetBasename = FileHelper::basenameWithoutExtension($fileDuplicate->getTarget());

        /** @var Rename|null $canonicalRename */
        $canonicalRename         = null;
        $canonicalHasLivePhotoId = false;
        $canonicalExactName      = false;

        foreach ($renames as $rename) {
            $sourcePath     = $rename->getSource()->getPathname();
            $sourceBasename = FileHelper::basenameWithoutExtension($rename->getSource());
            $exactName      = $sourceBasename === $canonicalTargetBasename;

            // Allow files whose source already has the canonical name through
            // regardless of target directory (idempotent canonical preference).
            if (!$exactName && ($rename->getTarget()->getPathname() !== $canonicalTargetPath)) {
                continue;
            }

            $hasLivePhotoId = isset($contentIdentifierMap[$sourcePath]);

            if ($canonicalRename === null) {
                $canonicalRename         = $rename;
                $canonicalHasLivePhotoId = $hasLivePhotoId;
                $canonicalExactName      = $exactName;
            }

            // Priority 1: source already has the canonical base name (idempotency).
            if ($exactName && !$canonicalExactName) {
                $canonicalRename    = $rename;
                $canonicalExactName = true;

                break;
            }

            // Priority 2: file has a Live Photo content ID (original capture).
            if ($hasLivePhotoId && !$canonicalHasLivePhotoId && !$canonicalExactName) {
                $canonicalRename         = $rename;
                $canonicalHasLivePhotoId = true;
            }
        }

        // If another file in the group (any extension) already occupies the
        // unsuffixed base name, the canonical does not need promotion — the
        // base name is already taken by a different extension variant.
        $canonicalNeedsPromotion = true;

        if (($canonicalRename instanceof Rename) && !$canonicalExactName) {
            foreach ($renames as $rename) {
                if ($rename === $canonicalRename) {
                    continue;
                }

                if ($rename->getSource()->getPathname() === $rename->getTarget()->getPathname()) {
                    $canonicalNeedsPromotion = false;

                    break;
                }
            }
        }

        return new DuplicateCanonicalSelection(
            $canonicalRename,
            $canonicalExactName,
            $canonicalNeedsPromotion,
        );
    }
}
