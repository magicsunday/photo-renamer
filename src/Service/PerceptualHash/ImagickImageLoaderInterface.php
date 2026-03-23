<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use Imagick;
use SplFileInfo;

/**
 * Loads and normalizes images for perceptual comparison.
 * Handles color space conversion (sRGB), EXIF orientation, alpha removal,
 * and video poster frame extraction.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface ImagickImageLoaderInterface
{
    /**
     * Loads an image file and returns a normalized Imagick instance.
     * For videos, extracts a poster frame via ffmpeg first.
     *
     * Normalization: autoOrient → stripImage → sRGB → removeAlpha → flatten.
     * This ensures consistent pixel values regardless of source format,
     * color profile, or EXIF orientation.
     *
     * The caller is responsible for destroying the returned Imagick instance.
     *
     * @return Imagick|null Normalized image, or null on failure
     */
    public function loadNormalized(SplFileInfo $file): ?Imagick;
}
