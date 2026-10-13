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

use function in_array;
use function strtolower;

/**
 * Classifies still images and videos by extension and applies the shared
 * media-family pair rules used by Live Photo and duplicate matching.
 * Unknown extensions belong to neither supported family.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class MediaTypeClassifier
{
    /**
     * Extensions that identify still image assets within Live Photo groups.
     *
     * @var list<string>
     */
    public const array LIVE_PHOTO_STILL_EXTENSIONS = ['heic', 'heif', 'jpg', 'jpeg'];

    /**
     * Extensions that identify video assets.
     *
     * @var list<string>
     */
    public const array VIDEO_EXTENSIONS = ['avi', 'mov', 'mp4', 'm4v'];

    /**
     * Checks whether the given file is classified as a still image.
     *
     * Still images are typically HEIC, HEIF, JPG, or JPEG files. In the
     * context of Live Photos, they represent the primary asset of a pair.
     *
     * @param SplFileInfo $fileInfo The file information to check.
     *
     * @return bool True if the file extension matches a known image format.
     */
    public function isLivePhotoStill(SplFileInfo $fileInfo): bool
    {
        $extension = strtolower($fileInfo->getExtension());

        if ($extension === '') {
            return false;
        }

        return in_array($extension, self::LIVE_PHOTO_STILL_EXTENSIONS, true);
    }

    /**
     * Checks whether the given file is classified as a video.
     *
     * Videos include formats like MOV, MP4, AVI, or M4V. In the context of
     * Live Photos, they represent the companion asset of a pair.
     *
     * @param SplFileInfo $fileInfo The file information to check.
     *
     * @return bool True if the file extension matches a known video format.
     */
    public function isVideo(SplFileInfo $fileInfo): bool
    {
        return in_array(
            strtolower($fileInfo->getExtension()),
            self::VIDEO_EXTENSIONS,
            true,
        );
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
        return $this->isLivePhotoStill($fileA) && $this->isLivePhotoStill($fileB);
    }

    /**
     * Returns whether the files belong to different media families.
     *
     * Classification is conservative: it only reports a cross-family pairing when one
     * file is classified as a still image and the other as a video. Unsupported or
     * unknown families do not establish a cross-family pair.
     *
     * @param SplFileInfo $fileA First file to compare
     * @param SplFileInfo $fileB Second file to compare
     *
     * @return bool True when one file is a still image and the other is a video
     */
    public function areDifferentMediaFamilies(SplFileInfo $fileA, SplFileInfo $fileB): bool
    {
        if ($this->isLivePhotoStill($fileA) && $this->isVideo($fileB)) {
            return true;
        }

        return $this->isVideo($fileA) && $this->isLivePhotoStill($fileB);
    }
}
