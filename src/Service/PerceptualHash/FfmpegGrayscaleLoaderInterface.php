<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use SplFileInfo;

/**
 * Loads a grayscale pixel matrix from an image or video file.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface FfmpegGrayscaleLoaderInterface
{
    /**
     * Loads a grayscale pixel matrix from the given file, resized to the requested dimensions.
     * For video files, extracts a poster frame first. Returns null on failure.
     *
     * @param SplFileInfo $file   The image or video file
     * @param int         $width  Target matrix width in pixels
     * @param int         $height Target matrix height in pixels
     *
     * @return array<int, array<int, float>>|null Matrix [y][x] with luma values 0-255
     */
    public function loadGrayscaleMatrix(SplFileInfo $file, int $width, int $height): ?array;
}
