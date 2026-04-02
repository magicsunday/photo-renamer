<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

/**
 * Immutable result of a local pixel-level difference analysis between two images.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LocalDiffResult
{
    /**
     * @param float $rmse              Root Mean Square Error of luminance values (Y = 0.299R + 0.587G + 0.114B),
     *                                 normalized to 0.0–1.0. Equivalent to Imagick grayscale RMSE.
     *                                 Codec-agnostic: HEIC↔JPG format backups produce 0.001–0.013,
     *                                 different photos produce 0.25+.
     * @param float $changedAreaRatio  Fraction of pixels that differ above noise threshold (0.0–1.0).
     *                                 Superseded by $rmse — will be removed in a future version.
     * @param float $largestBlobRatio  Fraction of image covered by the largest contiguous changed region (0.0–1.0).
     *                                 Superseded by $rmse — will be removed in a future version.
     * @param int   $blobCount         Number of contiguous changed regions after morphological cleanup.
     *                                 Superseded by $rmse — will be removed in a future version.
     * @param bool  $hasCompactRetouch True when a spatially coherent edit is detected (blob ratio ≥ threshold).
     *                                 Superseded by $rmse — will be removed in a future version.
     * @param bool  $success           False when the analysis failed (Imagick error) — distinguishes
     *                                 failure (rmse=0, success=false) from perfect match (rmse=0, success=true)
     * @param float $chromaDifference  Absolute difference in mean chroma energy between the two images,
     *                                 normalized to 0.0–1.0. Detects color→grayscale conversions:
     *                                 near 0 for same-colorspace pairs, high for desaturation.
     */
    public function __construct(
        public float $rmse,
        public float $changedAreaRatio,
        public float $largestBlobRatio,
        public int $blobCount,
        public bool $hasCompactRetouch,
        public bool $success = true,
        public float $chromaDifference = 0.0,
    ) {
    }
}
