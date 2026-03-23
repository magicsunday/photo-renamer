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
 * Immutable result of a multi-signal perceptual similarity comparison
 * between two media files.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SimilarityResult
{
    /**
     * @param int        $score          Combined similarity score 0–100 (100 = identical)
     * @param int        $dhashDistance  dHash Hamming distance (0–64)
     * @param int        $whashDistance  wHash Hamming distance (0–64)
     * @param float      $hfEnergyDelta  High-frequency energy difference (0.0+)
     * @param float      $colorDistance  Color histogram L1 distance (0.0–1.0)
     * @param float|null $durationDelta  Video duration difference in seconds (null for images)
     * @param string     $classification One of: duplicate_likely, edited_variant, different
     */
    public function __construct(
        public int $score,
        public int $dhashDistance,
        public int $whashDistance,
        public float $hfEnergyDelta,
        public float $colorDistance,
        public ?float $durationDelta,
        public string $classification,
    ) {
    }

    public function isDuplicateLikely(): bool
    {
        return $this->classification === 'duplicate_likely';
    }
}
