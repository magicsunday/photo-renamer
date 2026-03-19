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
final class MediaTypeClassifier implements MediaTypeClassifierInterface
{
    /**
     * Extensions that identify still image assets within Live Photo groups.
     *
     * @var list<string>
     */
    public const array LIVE_PHOTO_STILL_EXTENSIONS = ['heic', 'heif', 'jpg', 'jpeg'];

    /**
     * Checks whether the given file is a still image (HEIC, HEIF, JPG, JPEG) as opposed
     * to a video companion (MOV, MP4). Used to determine media type boundaries during
     * Live Photo companion detection and hash sub-group exclusion.
     *
     * @param SplFileInfo $fileInfo File to classify
     *
     * @return bool True when the file extension matches a known still image format
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
}
