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

/**
 * Performs pixel-level local difference analysis between two normalized images.
 *
 * This is Stage B of the duplicate detection pipeline — only invoked for
 * image pairs that scored ≥ 95 in Stage A (global signals report near-identical)
 * but have different content hashes. It distinguishes JPEG re-encode noise
 * (scattered, uniform diffs) from local retouches (compact blobs).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface LocalDifferenceAnalyzerInterface
{
    /**
     * Analyzes the pixel-level differences between two already-normalized Imagick images.
     *
     * Both images must be normalized (autoOrient + sRGB + stripped) before passing.
     * The analyzer downscales to a working resolution, computes pixel differences,
     * applies noise thresholding and morphological cleanup, then measures the
     * spatial structure of remaining differences.
     */
    public function analyze(Imagick $imageA, Imagick $imageB): LocalDiffResult;
}
