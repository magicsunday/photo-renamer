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
 * Immutable grayscale RMSE/chroma measurements with explicit evidence availability.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LocalDiffResult
{
    /**
     * Retains only measured values and availability so a failed analysis cannot
     * be confused with a perfect match. Threshold decisions remain in the classifier.
     *
     * @param float $rmse             Normalized RMSE of aligned Imagick grayscale pixels.
     * @param bool  $success          False when analysis is unavailable; zero RMSE alone is not match evidence.
     * @param float $chromaDifference Normalized absolute difference in mean RGB chroma energy.
     */
    public function __construct(
        public float $rmse,
        public bool $success = true,
        public float $chromaDifference = 0.0,
    ) {
    }
}
