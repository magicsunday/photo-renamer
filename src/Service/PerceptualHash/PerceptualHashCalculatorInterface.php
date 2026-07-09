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
 * Multi-signal perceptual similarity scoring for visual duplicate detection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface PerceptualHashCalculatorInterface
{
    /**
     * Computes a multi-signal similarity score between two files.
     * Uses dHash, wHash, HF-energy, color histogram, and optional video duration.
     *
     * @param SplFileInfo $fileA     First media file to compare.
     * @param SplFileInfo $fileB     Second media file to compare.
     * @param float|null  $durationA Video duration of file A in seconds (null for images).
     * @param float|null  $durationB Video duration of file B in seconds (null for images).
     *
     * @return SimilarityResult Object containing individual signal deltas and a semantic classification.
     */
    public function similarityScore(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        ?float $durationA = null,
        ?float $durationB = null,
    ): SimilarityResult;

    /**
     * Releases all cached hash results and Imagick instances from memory to free resources.
     * This should be called after processing a group to avoid memory leaks during
     * long-running batch operations.
     */
    public function clearCache(): void;
}
