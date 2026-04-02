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
use function min;
use function round;
use function sqrt;

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
final class LocalDifferenceAnalyzer
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

    /**
     * Computes luma RMSE + chroma difference (fast path). Blob fields are zeroed.
     * Exports RGB pixels once, derives luminance RMSE (equivalent to grayscale
     * Imagick RMSE, preserves calibrated thresholds) and a separate chroma energy
     * difference that detects color→grayscale conversions.
     * Returns success=false on Imagick errors.
     */
    public function analyzeRmse(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        try {
            [$pixelsA, $pixelsB, $totalPixels] = $this->exportRgbPixels($imageA, $imageB);

            $rmse       = $this->computeLumaRmse($pixelsA, $pixelsB, $totalPixels);
            $chromaDiff = $this->computeChromaDifference($pixelsA, $pixelsB, $totalPixels);

            return new LocalDiffResult($rmse, 0.0, 0.0, 0, false, chromaDifference: $chromaDiff);
        } catch (Throwable) {
            return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: false);
        }
    }

    /**
     * Computes luma RMSE + chroma difference and runs legacy blob analysis.
     * Downscales once, exports RGB for luma/chroma metrics, then converts
     * to grayscale in-place for the blob mask.
     * Returns success=false on Imagick errors.
     */
    public function analyzeDetailed(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        try {
            // Downscale once, reuse for both RGB and grayscale export
            $scaledA = $this->downscale($imageA);
            $scaledB = $this->downscale($imageB);

            $width  = $scaledA->getImageWidth();
            $height = $scaledA->getImageHeight();

            if (($width !== $scaledB->getImageWidth()) || ($height !== $scaledB->getImageHeight())) {
                $scaledB->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1.0, false);
            }

            $totalPixels = $width * $height;

            // RGB export for luma RMSE and chroma difference
            /** @var list<int> $rgbA */
            $rgbA = $scaledA->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);

            /** @var list<int> $rgbB */
            $rgbB = $scaledB->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);

            $rmse       = $this->computeLumaRmse($rgbA, $rgbB, $totalPixels);
            $chromaDiff = $this->computeChromaDifference($rgbA, $rgbB, $totalPixels);

            // Convert to grayscale in-place for blob mask
            $scaledA->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            $scaledB->transformImageColorspace(Imagick::COLORSPACE_GRAY);

            /** @var list<int> $grayA */
            $grayA = $scaledA->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

            /** @var list<int> $grayB */
            $grayB = $scaledB->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

            $scaledA->clear();
            $scaledB->clear();

            $result = $this->doLegacyBlobAnalysis($grayA, $grayB, $totalPixels, $width, $height, $rmse);

            return new LocalDiffResult(
                $result->rmse,
                $result->changedAreaRatio,
                $result->largestBlobRatio,
                $result->blobCount,
                $result->hasCompactRetouch,
                chromaDifference: $chromaDiff,
            );
        } catch (Throwable) {
            return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: false);
        }
    }

    /**
     * Backward-compatible entry point. Delegates to analyzeRmse().
     */
    public function analyze(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        return $this->analyzeRmse($imageA, $imageB);
    }

    /**
     * Downscales both images to working resolution, exports RGB pixels (3 values per pixel).
     *
     * @return array{list<int>, list<int>, int, int, int} [pixelsA, pixelsB, totalPixels, width, height]
     */
    private function exportRgbPixels(Imagick $imageA, Imagick $imageB): array
    {
        $scaledA = $this->downscale($imageA);
        $scaledB = $this->downscale($imageB);

        $width  = $scaledA->getImageWidth();
        $height = $scaledA->getImageHeight();

        if (($width !== $scaledB->getImageWidth()) || ($height !== $scaledB->getImageHeight())) {
            $scaledB->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1.0, false);
        }

        /** @var list<int> $pixelsA */
        $pixelsA = $scaledA->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);

        /** @var list<int> $pixelsB */
        $pixelsB = $scaledB->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);

        $scaledA->clear();
        $scaledB->clear();

        return [$pixelsA, $pixelsB, $width * $height, $width, $height];
    }

    /**
     * Computes RMSE from luminance (Y = 0.299R + 0.587G + 0.114B), normalized
     * to 0.0–1.0. Equivalent to Imagick grayscale RMSE — preserves the calibrated
     * SAFE_MERGE_RMSE thresholds for codec noise detection.
     *
     * @param list<int> $pixelsA RGB pixel values (3 per pixel)
     * @param list<int> $pixelsB RGB pixel values (3 per pixel)
     */
    private function computeLumaRmse(array $pixelsA, array $pixelsB, int $totalPixels): float
    {
        $sumSquaredErr = 0.0;

        for ($i = 0; $i < $totalPixels; ++$i) {
            $offset = $i * 3;

            $lumaA = 0.299 * $pixelsA[$offset] + 0.587 * $pixelsA[$offset + 1] + 0.114 * $pixelsA[$offset + 2];
            $lumaB = 0.299 * $pixelsB[$offset] + 0.587 * $pixelsB[$offset + 1] + 0.114 * $pixelsB[$offset + 2];

            $diff = $lumaA - $lumaB;
            $sumSquaredErr += $diff * $diff;
        }

        return sqrt($sumSquaredErr / $totalPixels) / 255.0;
    }

    /**
     * Computes the absolute difference in mean chroma energy between two images,
     * normalized to 0.0–1.0. Chroma energy per pixel = max(R,G,B) - min(R,G,B):
     * 0 for grayscale pixels, positive for colored pixels.
     *
     * Detects color→grayscale conversions: one image has high chroma energy,
     * the other has near-zero → large difference. Codec conversions (HEIC→JPG)
     * preserve chroma → near-zero difference.
     *
     * @param list<int> $pixelsA RGB pixel values (3 per pixel)
     * @param list<int> $pixelsB RGB pixel values (3 per pixel)
     */
    private function computeChromaDifference(array $pixelsA, array $pixelsB, int $totalPixels): float
    {
        $sumA = 0.0;
        $sumB = 0.0;

        for ($i = 0; $i < $totalPixels; ++$i) {
            $offset = $i * 3;

            $rA = $pixelsA[$offset];
            $gA = $pixelsA[$offset + 1];
            $bA = $pixelsA[$offset + 2];
            $sumA += max($rA, $gA, $bA) - min($rA, $gA, $bA);

            $rB = $pixelsB[$offset];
            $gB = $pixelsB[$offset + 1];
            $bB = $pixelsB[$offset + 2];
            $sumB += max($rB, $gB, $bB) - min($rB, $gB, $bB);
        }

        $meanChromaA = $sumA / $totalPixels;
        $meanChromaB = $sumB / $totalPixels;

        return abs($meanChromaA - $meanChromaB) / 255.0;
    }

    /**
     * Legacy blob analysis pipeline: binary mask → morphological opening → flood-fill.
     * Superseded by RMSE. Kept for analyzeDetailed() which needs blob metrics.
     *
     * @param list<int> $pixelsA
     * @param list<int> $pixelsB
     */
    private function doLegacyBlobAnalysis(
        array $pixelsA,
        array $pixelsB,
        int $totalPixels,
        int $width,
        int $height,
        float $rmse,
    ): LocalDiffResult {
        $mask         = [];
        $changedCount = 0;

        for ($i = 0; $i < $totalPixels; ++$i) {
            $absDiff = abs($pixelsA[$i] - $pixelsB[$i]);

            if ($absDiff > self::NOISE_THRESHOLD) {
                $mask[$i] = 1;
                ++$changedCount;
            } else {
                $mask[$i] = 0;
            }
        }

        if ($changedCount === 0) {
            return new LocalDiffResult($rmse, 0.0, 0.0, 0, false);
        }

        $mask         = $this->morphologicalOpen($mask, $width, $height);
        $changedCount = 0;

        foreach ($mask as $v) {
            $changedCount += $v;
        }

        if ($changedCount === 0) {
            return new LocalDiffResult($rmse, 0.0, 0.0, 0, false);
        }

        [$blobCount, $largestBlobArea] = $this->findBlobs($mask, $width, $height);

        $changedAreaRatio  = $changedCount / $totalPixels;
        $largestBlobRatio  = $largestBlobArea / $totalPixels;
        $hasCompactRetouch = $largestBlobRatio >= self::RETOUCH_BLOB_THRESHOLD;

        return new LocalDiffResult(
            $rmse,
            $changedAreaRatio,
            $largestBlobRatio,
            $blobCount,
            $hasCompactRetouch,
        );
    }

    /**
     * Downscales image to working resolution without colorspace conversion.
     */
    private function downscale(Imagick $img): Imagick
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
