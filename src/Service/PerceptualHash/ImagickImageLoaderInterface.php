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
     * The caller is responsible for clearing the returned Imagick instance via clear().
     *
     * @param int|null $maxResolution Maximum pixel dimension hint for the decoder.
     *                                When set, the JPEG decoder loads at reduced resolution
     *                                (nearest 1/2/4/8 that covers this size) instead of full res.
     *                                Use 256 for hash computation, null for Stage B full analysis.
     *
     * @return Imagick|null Normalized image, or null on failure
     */
    public function loadNormalized(SplFileInfo $file, ?int $maxResolution = null): ?Imagick;
}
