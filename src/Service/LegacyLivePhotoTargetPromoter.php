<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\FileDuplicate;
use SplFileInfo;

use function str_starts_with;

/**
 * Promotes Live Photo duplicate groups so the still image owns the canonical target.
 *
 * During legacy grouping a MOV may appear before its paired HEIC/JPG. This policy
 * ensures the group's canonical target is replaced once a still target is seen, so
 * later companion naming and suffix assignment always inherit the still image's base
 * name rather than the video's.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyLivePhotoTargetPromoter
{
    /**
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Classifier used to distinguish still targets from video targets.
     */
    public function __construct(private MediaTypeClassifierInterface $mediaTypeClassifier)
    {
    }

    /**
     * Promotes the group's canonical target to a still image when needed.
     *
     * Only Live Photo groups participate. If the existing group target already is
     * a still, nothing changes. Otherwise a still candidate replaces the current
     * video target so the group's basename source remains photo-first.
     *
     * @param string        $duplicateIdentifier Group key; only `live-photo:` prefixed groups are eligible.
     * @param FileDuplicate $fileDuplicate       Duplicate group whose canonical target may be replaced.
     * @param SplFileInfo   $candidateTarget     Newly encountered target considered for promotion.
     */
    public function promote(
        string $duplicateIdentifier,
        FileDuplicate $fileDuplicate,
        SplFileInfo $candidateTarget,
    ): void {
        if (!str_starts_with($duplicateIdentifier, Constants::LIVE_PHOTO_IDENTIFIER_PREFIX)) {
            return;
        }

        if (!$this->mediaTypeClassifier->isLivePhotoStill($candidateTarget)) {
            return;
        }

        if ($this->mediaTypeClassifier->isLivePhotoStill($fileDuplicate->getTarget())) {
            return;
        }

        $fileDuplicate->setTarget($candidateTarget);
    }
}
