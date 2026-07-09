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
     * @param int                      $score          Combined similarity score 0–100 (100 = identical)
     * @param int                      $dhashDistance  dHash Hamming distance (0–64)
     * @param int                      $whashDistance  wHash Hamming distance (0–64)
     * @param float                    $hfEnergyDelta  High-frequency energy difference (0.0+)
     * @param float                    $colorDistance  Color histogram L1 distance (0.0–1.0)
     * @param float|null               $durationDelta  Video duration difference in seconds (null for images)
     * @param SimilarityClassification $classification Semantic classification of the similarity
     */
    public function __construct(
        public int $score,
        public int $dhashDistance,
        public int $whashDistance,
        public float $hfEnergyDelta,
        public float $colorDistance,
        public ?float $durationDelta,
        public SimilarityClassification $classification,
    ) {
    }

    /**
     * Checks if the compared media files are classified as likely duplicates.
     *
     * A "likely duplicate" means that the combined multi-signal score and the individual
     * distances (dHash, wHash, etc.) are within thresholds that suggest the files
     * represent the same content with minimal variations (e.g., metadata changes).
     *
     * @return bool True if the classification is DuplicateLikely, false otherwise.
     */
    public function isDuplicateLikely(): bool
    {
        return $this->classification === SimilarityClassification::DuplicateLikely;
    }

    /**
     * Checks if the compared media files are classified as an edited variant.
     *
     * An "edited variant" suggests that the files originate from the same source
     * but have undergone modifications like resizing, re-encoding, or color
     * grading, while still maintaining high perceptual similarity.
     *
     * @return bool True if the classification is EditedVariant, false otherwise.
     */
    public function isEditedVariant(): bool
    {
        return $this->classification === SimilarityClassification::EditedVariant;
    }

    /**
     * Checks if the compared media files are classified as different.
     *
     * This classification is used when the similarity score or distances exceed
     * the thresholds for duplicates or edited variants, indicating that the files
     * represent distinct content.
     *
     * @return bool True if the classification is Different, false otherwise.
     */
    public function isDifferent(): bool
    {
        return $this->classification === SimilarityClassification::Different;
    }
}
