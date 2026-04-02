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
 * Contract for classifying a file's media type based on its extension.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface MediaTypeClassifierInterface
{
    /**
     * Checks whether the given file is a still image.
     *
     * Still images are typically HEIC, HEIF, JPG, or JPEG files. In the context
     * of Live Photos, this is the primary asset of a pair.
     *
     * @param SplFileInfo $fileInfo The file to classify.
     *
     * @return bool True if the file extension matches a known still image format.
     */
    public function isLivePhotoStill(SplFileInfo $fileInfo): bool;

    /**
     * Checks whether the given file is a video.
     *
     * Videos include formats like MOV, MP4, AVI, or MKV. In the context
     * of Live Photos, this is the companion asset of a pair.
     *
     * @param SplFileInfo $fileInfo The file to classify.
     *
     * @return bool True if the file extension matches a known video format.
     */
    public function isVideo(SplFileInfo $fileInfo): bool;
}
