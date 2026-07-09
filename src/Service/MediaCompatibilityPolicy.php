<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use SplFileInfo;

/**
 * Encapsulates shared still/video compatibility decisions used by multiple commands
 * and pipeline services.
 *
 * The policy intentionally stays narrow. It does not decide naming, duplicate
 * ranking, or Live Photo semantics on its own; it only answers repeated questions
 * about still/video family membership and cross-family compatibility.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class MediaCompatibilityPolicy
{
    /**
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Classifies files as still images or videos
     */
    public function __construct(
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
    }

    /**
     * Returns whether the file belongs to the still-image family.
     *
     * @param SplFileInfo $file File to classify
     *
     * @return bool True when the file is a still image such as HEIC, HEIF, JPG, or JPEG
     */
    public function isStillImage(SplFileInfo $file): bool
    {
        return $this->mediaTypeClassifier->isLivePhotoStill($file);
    }

    /**
     * Returns whether the file belongs to the video family.
     *
     * @param SplFileInfo $file File to classify
     *
     * @return bool True when the file is a supported video such as MOV or MP4
     */
    public function isVideo(SplFileInfo $file): bool
    {
        return $this->mediaTypeClassifier->isVideo($file);
    }

    /**
     * Returns whether both files belong to the still-image family.
     *
     * This is used by commands such as `rename:dedup` that allow cross-extension
     * matching between still formats while still rejecting video/still pairings.
     *
     * @param SplFileInfo $fileA First file to compare
     * @param SplFileInfo $fileB Second file to compare
     *
     * @return bool True when both files are classified as still images
     */
    public function areBothStillImages(SplFileInfo $fileA, SplFileInfo $fileB): bool
    {
        return $this->isStillImage($fileA) && $this->isStillImage($fileB);
    }

    /**
     * Returns whether the files belong to different media families.
     *
     * The policy is conservative: it only reports a cross-family pairing when one
     * file is classified as a still image and the other as a video. Unsupported or
     * unknown families are not treated as compatible.
     *
     * @param SplFileInfo $fileA First file to compare
     * @param SplFileInfo $fileB Second file to compare
     *
     * @return bool True when one file is a still image and the other is a video
     */
    public function areDifferentMediaFamilies(SplFileInfo $fileA, SplFileInfo $fileB): bool
    {
        if ($this->isStillImage($fileA) && $this->isVideo($fileB)) {
            return true;
        }

        return $this->isVideo($fileA) && $this->isStillImage($fileB);
    }
}
