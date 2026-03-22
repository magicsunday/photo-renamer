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
 * Computes perceptual hashes (dHash) and measures their similarity
 * via Hamming distance.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface PerceptualHashCalculatorInterface
{
    /**
     * Computes a 64-bit difference hash (dHash) of the file's visual content.
     * Returns a 16-character hex string, or null on failure.
     */
    public function computeDhash(SplFileInfo $file): ?string;

    /**
     * Computes the Hamming distance between two hex-encoded hashes.
     * Returns the number of differing bits (0 = identical, 64 = maximally different).
     */
    public function hammingDistance(string $hashA, string $hashB): int;

    /**
     * Releases all cached hash results to free memory.
     */
    public function clearCache(): void;
}
