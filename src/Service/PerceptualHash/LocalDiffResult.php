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
 * When global perceptual hashes report near-identical images (score ≥ 95),
 * this analysis detects whether the few differing pixels form compact blobs
 * (indicating local retouching) or are scattered noise (indicating re-encoding).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LocalDiffResult
{
    /**
     * @param float $changedAreaRatio  Fraction of pixels that differ above noise threshold (0.0–1.0)
     * @param float $largestBlobRatio  Fraction of image covered by the largest contiguous changed region (0.0–1.0)
     * @param int   $blobCount         Number of contiguous changed regions after morphological cleanup
     * @param bool  $hasCompactRetouch True when a spatially coherent edit is detected (blob ratio ≥ threshold)
     */
    public function __construct(
        public float $changedAreaRatio,
        public float $largestBlobRatio,
        public int $blobCount,
        public bool $hasCompactRetouch,
    ) {
    }
}
