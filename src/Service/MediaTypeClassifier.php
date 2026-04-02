<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use Override;
use SplFileInfo;

use function in_array;
use function strtolower;

/**
 * Standalone implementation of the MediaTypeClassifierInterface. Classifies
 * a file as a still image (HEIC, HEIF, JPG, JPEG) or a video companion
 * (MOV, MP4) based solely on its extension.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class MediaTypeClassifier implements MediaTypeClassifierInterface
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
    #[Override]
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
    #[Override]
    public function isVideo(SplFileInfo $fileInfo): bool
    {
        return in_array(
            strtolower($fileInfo->getExtension()),
            self::VIDEO_EXTENSIONS,
            true,
        );
    }
}
