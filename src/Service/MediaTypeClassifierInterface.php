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
     * Checks whether the given file is a still image (HEIC, HEIF, JPG, JPEG) as opposed
     * to a video companion (MOV, MP4).
     *
     * @param SplFileInfo $fileInfo File to classify
     *
     * @return bool True when the file extension matches a known still image format
     */
    public function isLivePhotoStill(SplFileInfo $fileInfo): bool;
}
