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
use Throwable;

use function abs;
use function array_fill;
use function array_pop;
use function intdiv;
use function max;
use function round;

/**
 * Pixel-level local difference analysis for near-identical image pairs.
 *
 * Downscales both images to a working resolution, computes per-pixel
 * absolute difference, applies noise thresholding and morphological
 * opening (erode + dilate), then measures contiguous changed regions
 * via flood-fill connected component analysis.
 *
 * Distinguishes:
 * - JPEG re-encode noise: scattered tiny diffs, no coherent blobs
 * - Local retouch (e.g. license plate): compact spatial blob(s)
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LocalDifferenceAnalyzer
{
    /**
     * Working resolution: long edge scaled to this many pixels.
     */
    private const int WORK_SIZE = 512;

    /**
     * Pixel differences below this threshold (0–255) are considered JPEG noise.
     */
    private const int NOISE_THRESHOLD = 6;

    /**
     * Minimum blob area ratio to consider as a compact retouch.
     */
    private const float RETOUCH_BLOB_THRESHOLD = 0.001;

    public function analyze(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        try {
            return $this->doAnalyze($imageA, $imageB);
        } catch (Throwable) {
            // On any failure, report no retouch detected (conservative)
            return new LocalDiffResult(0.0, 0.0, 0, false);
        }
    }

    private function doAnalyze(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        // Downscale both to working resolution
        $grayA = $this->downscaleGray($imageA);
        $grayB = $this->downscaleGray($imageB);

        $width  = $grayA->getImageWidth();
        $height = $grayA->getImageHeight();

        // Ensure same dimensions
        if (($width !== $grayB->getImageWidth()) || ($height !== $grayB->getImageHeight())) {
            $grayB->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1.0, false);
        }

        // Export grayscale pixels
        /** @var list<int> $pixelsA */
        $pixelsA = $grayA->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

        /** @var list<int> $pixelsB */
        $pixelsB = $grayB->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

        $grayA->clear();
        $grayB->clear();

        $totalPixels = $width * $height;

        // Build binary mask: 1 = changed pixel (above noise threshold)
        $mask         = [];
        $changedCount = 0;

        for ($i = 0; $i < $totalPixels; ++$i) {
            $diff = abs($pixelsA[$i] - $pixelsB[$i]);

            if ($diff > self::NOISE_THRESHOLD) {
                $mask[$i] = 1;
                ++$changedCount;
            } else {
                $mask[$i] = 0;
            }
        }

        if ($changedCount === 0) {
            return new LocalDiffResult(0.0, 0.0, 0, false);
        }

        // Morphological opening: erode then dilate to remove isolated noise pixels
        $mask = $this->morphologicalOpen($mask, $width, $height);

        // Recount after morphology
        $changedCount = 0;

        foreach ($mask as $v) {
            $changedCount += $v;
        }

        if ($changedCount === 0) {
            return new LocalDiffResult(0.0, 0.0, 0, false);
        }

        // Connected component analysis via flood-fill
        [$blobCount, $largestBlobArea] = $this->findBlobs($mask, $width, $height);

        $changedAreaRatio  = $changedCount / $totalPixels;
        $largestBlobRatio  = $largestBlobArea / $totalPixels;
        $hasCompactRetouch = $largestBlobRatio >= self::RETOUCH_BLOB_THRESHOLD;

        return new LocalDiffResult(
            $changedAreaRatio,
            $largestBlobRatio,
            $blobCount,
            $hasCompactRetouch,
        );
    }

    /**
     * Downscales and converts to grayscale at working resolution.
     */
    private function downscaleGray(Imagick $img): Imagick
    {
        $clone  = clone $img;
        $width  = $clone->getImageWidth();
        $height = $clone->getImageHeight();

        if (($width > self::WORK_SIZE) || ($height > self::WORK_SIZE)) {
            $scale = self::WORK_SIZE / max($width, $height);
            $clone->resizeImage(
                (int) round($width * $scale),
                (int) round($height * $scale),
                Imagick::FILTER_TRIANGLE,
                1.0,
                false,
            );
        }

        $clone->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        return $clone;
    }

    /**
     * Morphological opening (erode then dilate) with a 3×3 cross kernel.
     * Removes isolated single-pixel noise while preserving compact regions.
     *
     * @param array<int, int> $mask
     *
     * @return array<int, int>
     */
    private function morphologicalOpen(array $mask, int $width, int $height): array
    {
        // Erode: pixel must have at least one 4-connected neighbor set
        $eroded = $this->erode($mask, $width, $height);

        // Dilate: expand remaining regions back
        return $this->dilate($eroded, $width, $height);
    }

    /**
     * @param array<int, int> $mask
     *
     * @return array<int, int>
     */
    private function erode(array $mask, int $width, int $height): array
    {
        $result = array_fill(0, $width * $height, 0);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $idx = $y * $width + $x;

                if ($mask[$idx] === 0) {
                    $result[$idx] = 0;

                    continue;
                }

                // Keep pixel only if it has at least one 4-connected neighbor
                $hasNeighbor = (($x > 0) && ($mask[$idx - 1] === 1))
                    || (($x < $width - 1) && ($mask[$idx + 1] === 1))
                    || (($y > 0) && ($mask[$idx - $width] === 1))
                    || (($y < $height - 1) && ($mask[$idx + $width] === 1));

                $result[$idx] = $hasNeighbor ? 1 : 0;
            }
        }

        return $result;
    }

    /**
     * @param array<int, int> $mask
     *
     * @return array<int, int>
     */
    private function dilate(array $mask, int $width, int $height): array
    {
        $result = array_fill(0, $width * $height, 0);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $idx = $y * $width + $x;

                if ($mask[$idx] === 1) {
                    $result[$idx] = 1;

                    continue;
                }

                // Set pixel if any 4-connected neighbor is set
                $hasNeighbor = (($x > 0) && ($mask[$idx - 1] === 1))
                    || (($x < $width - 1) && ($mask[$idx + 1] === 1))
                    || (($y > 0) && ($mask[$idx - $width] === 1))
                    || (($y < $height - 1) && ($mask[$idx + $width] === 1));

                $result[$idx] = $hasNeighbor ? 1 : 0;
            }
        }

        return $result;
    }

    /**
     * Finds connected components via iterative flood-fill.
     * Returns [blobCount, largestBlobArea].
     *
     * @param array<int, int> $mask
     *
     * @return array{int, int}
     */
    private function findBlobs(array $mask, int $width, int $height): array
    {
        $visited         = [];
        $blobCount       = 0;
        $largestBlobArea = 0;
        $totalPixels     = $width * $height;

        for ($i = 0; $i < $totalPixels; ++$i) {
            if ($mask[$i] === 0) {
                continue;
            }

            if (isset($visited[$i])) {
                continue;
            }

            // BFS flood-fill from this pixel.
            // Mark visited on enqueue (not dequeue) to prevent duplicate queue entries.
            $visited[$i] = true;
            $queue       = [$i];
            $area        = 0;

            while ($queue !== []) {
                $idx = array_pop($queue);
                ++$area;

                $x = $idx % $width;
                $y = intdiv($idx, $width);

                // 4-connected neighbors — only enqueue if not yet visited
                if ($x > 0) {
                    $n = $idx - 1;

                    if (!isset($visited[$n]) && ($mask[$n] === 1)) {
                        $visited[$n] = true;
                        $queue[]     = $n;
                    }
                }

                if ($x < $width - 1) {
                    $n = $idx + 1;

                    if (!isset($visited[$n]) && ($mask[$n] === 1)) {
                        $visited[$n] = true;
                        $queue[]     = $n;
                    }
                }

                if ($y > 0) {
                    $n = $idx - $width;

                    if (!isset($visited[$n]) && ($mask[$n] === 1)) {
                        $visited[$n] = true;
                        $queue[]     = $n;
                    }
                }

                if ($y < $height - 1) {
                    $n = $idx + $width;

                    if (!isset($visited[$n]) && ($mask[$n] === 1)) {
                        $visited[$n] = true;
                        $queue[]     = $n;
                    }
                }
            }

            ++$blobCount;

            if ($area > $largestBlobArea) {
                $largestBlobArea = $area;
            }
        }

        return [$blobCount, $largestBlobArea];
    }
}
