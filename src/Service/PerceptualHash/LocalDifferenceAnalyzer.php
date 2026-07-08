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
     * Computes the grayscale Root Mean Square Error (RMSE) and the RGB chroma
     * difference between two images using a fast path.
     *
     * This method is optimized for speed and does not perform the expensive
     * blob analysis. It is primarily used for the initial screening of
     * potential duplicates to quickly discard images that are clearly different.
     *
     * @param Imagick $imageA The first image to compare.
     * @param Imagick $imageB The second image to compare.
     *
     * @return LocalDiffResult A result object containing the RMSE and chroma
     *                         difference. Success is false if Imagick fails.
     */
    public function analyzeRmse(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        try {
            [$grayA, $grayB, $totalPixels, , , $chromaDiff] = $this->exportPixelData($imageA, $imageB);

            $rmse = $this->computeGrayscaleRmse($grayA, $grayB, $totalPixels);

            return new LocalDiffResult($rmse, 0.0, 0.0, 0, false, chromaDifference: $chromaDiff);
        } catch (Throwable) {
            return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: false);
        }
    }

    /**
     * Computes the grayscale RMSE and chroma difference, and performs a detailed
     * legacy blob analysis to detect local retouches.
     *
     * This method is more resource-intensive as it identifies spatially coherent
     * clusters of differences (blobs). It is used when a more precise decision
     * is required, for example, to distinguish between simple compression noise
     * and actual content modifications (like a blurred license plate).
     *
     * @param Imagick $imageA The first image to compare.
     * @param Imagick $imageB The second image to compare.
     *
     * @return LocalDiffResult A detailed result object including RMSE, chroma
     *                         difference, and blob statistics.
     */
    public function analyzeDetailed(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        try {
            [$grayA, $grayB, $totalPixels, $width, $height, $chromaDiff] = $this->exportPixelData($imageA, $imageB);

            $rmse   = $this->computeGrayscaleRmse($grayA, $grayB, $totalPixels);
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
     * A backward-compatible entry point that delegates to the fast RMSE analysis.
     *
     * This method is kept to maintain compatibility with existing callers that
     * don't require the detailed blob analysis by default.
     *
     * @param Imagick $imageA The first image to compare.
     * @param Imagick $imageB The second image to compare.
     *
     * @return LocalDiffResult The result of the fast RMSE/chroma analysis.
     */
    public function analyze(Imagick $imageA, Imagick $imageB): LocalDiffResult
    {
        return $this->analyzeRmse($imageA, $imageB);
    }

    /**
     * Downscales the images, exports pixel data, and prepares grayscale versions
     * for calibrated RMSE calculation.
     *
     * The method performs several critical steps:
     * 1. Downscales images to a manageable working size (WORK_SIZE).
     * 2. Synchronizes dimensions between both images.
     * 3. Exports RGB pixels to calculate the chroma difference.
     * 4. Converts images to a calibrated grayscale (Rec.709) for RMSE computation.
     *
     * @param Imagick $imageA The first image to process.
     * @param Imagick $imageB The second image to process.
     *
     * @return array{list<int>, list<int>, int, int, int, float} A tuple containing:
     *                                                           - grayA: Grayscale pixels of image A
     *                                                           - grayB: Grayscale pixels of image B
     *                                                           - totalPixels: Total number of pixels
     *                                                           - width: Working width
     *                                                           - height: Working height
     *                                                           - chromaDiff: Computed chroma difference
     */
    private function exportPixelData(Imagick $imageA, Imagick $imageB): array
    {
        $scaledA = $this->downscale($imageA);
        $scaledB = $this->downscale($imageB);

        $width  = $scaledA->getImageWidth();
        $height = $scaledA->getImageHeight();

        if (($width !== $scaledB->getImageWidth()) || ($height !== $scaledB->getImageHeight())) {
            $scaledB->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1.0, false);
        }

        $totalPixels = $width * $height;

        // RGB export for chroma difference
        /** @var list<int> $rgbA */
        $rgbA = $scaledA->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);

        /** @var list<int> $rgbB */
        $rgbB = $scaledB->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);

        $chromaDiff = $this->computeChromaDifference($rgbA, $rgbB, $totalPixels);

        // Convert to Imagick grayscale in-place for calibrated RMSE
        $scaledA->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $scaledB->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        /** @var list<int> $grayA */
        $grayA = $scaledA->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

        /** @var list<int> $grayB */
        $grayB = $scaledB->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

        $scaledA->clear();
        $scaledB->clear();

        return [$grayA, $grayB, $totalPixels, $width, $height, $chromaDiff];
    }

    /**
     * Computes RMSE normalized to 0.0–1.0 from two grayscale pixel arrays.
     * Operates on Imagick COLORSPACE_GRAY output to preserve calibrated thresholds.
     *
     * @param list<int> $pixelsA Grayscale pixel values (1 per pixel)
     * @param list<int> $pixelsB Grayscale pixel values (1 per pixel)
     */
    private function computeGrayscaleRmse(array $pixelsA, array $pixelsB, int $totalPixels): float
    {
        $sumSquaredErr = 0.0;

        for ($pixelIndex = 0; $pixelIndex < $totalPixels; ++$pixelIndex) {
            $diff = $pixelsA[$pixelIndex] - $pixelsB[$pixelIndex];
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

        for ($pixelIndex = 0; $pixelIndex < $totalPixels; ++$pixelIndex) {
            $offset = $pixelIndex * 3;

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

        for ($pixelIndex = 0; $pixelIndex < $totalPixels; ++$pixelIndex) {
            $absDiff = abs($pixelsA[$pixelIndex] - $pixelsB[$pixelIndex]);

            if ($absDiff > self::NOISE_THRESHOLD) {
                $mask[$pixelIndex] = 1;
                ++$changedCount;
            } else {
                $mask[$pixelIndex] = 0;
            }
        }

        if ($changedCount === 0) {
            return new LocalDiffResult($rmse, 0.0, 0.0, 0, false);
        }

        $mask         = $this->morphologicalOpen($mask, $width, $height);
        $changedCount = 0;

        foreach ($mask as $isChanged) {
            $changedCount += $isChanged;
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
     * Downscales the given image to a working resolution (max WORK_SIZE)
     * while preserving the original colorspace and aspect ratio.
     *
     * Using a fixed maximum dimension ensures consistent RMSE and blob analysis
     * results regardless of the original image resolution (e.g., 12MP vs 48MP).
     *
     * @param Imagick $img The source image.
     *
     * @return Imagick A scaled-down clone of the image. The caller is responsible for clearing it.
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
     * Shrinks regions of set pixels (1) in the binary mask.
     *
     * Part of the morphological opening. A pixel is only kept (1) if it has at
     * least one 4-connected neighbor that is also set. This effectively removes
     * isolated single-pixel 'islands' which are typical for JPEG compression artifacts.
     *
     * @param array<int, int> $mask   The binary mask to erode.
     * @param int             $width  Width of the mask.
     * @param int             $height Height of the mask.
     *
     * @return array<int, int> The eroded binary mask.
     */
    private function erode(array $mask, int $width, int $height): array
    {
        $result = array_fill(0, $width * $height, 0);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $pixelIndex = $y * $width + $x;

                if ($mask[$pixelIndex] === 0) {
                    $result[$pixelIndex] = 0;

                    continue;
                }

                $result[$pixelIndex] = $this->hasFourConnectedNeighbor(
                    $mask,
                    $width,
                    $height,
                    $x,
                    $y,
                    $pixelIndex,
                ) ? 1 : 0;
            }
        }

        return $result;
    }

    /**
     * Expands regions of set pixels (1) in the binary mask.
     *
     * Part of the morphological opening. Sets a pixel to 1 if it or any of its
     * 4-connected neighbors is already set to 1. This restores the size of
     * regions that were slightly shrunk during the erosion step.
     *
     * @param array<int, int> $mask   The binary mask to dilate.
     * @param int             $width  Width of the mask.
     * @param int             $height Height of the mask.
     *
     * @return array<int, int> The dilated binary mask.
     */
    private function dilate(array $mask, int $width, int $height): array
    {
        $result = array_fill(0, $width * $height, 0);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $pixelIndex = $y * $width + $x;

                if ($mask[$pixelIndex] === 1) {
                    $result[$pixelIndex] = 1;

                    continue;
                }

                $result[$pixelIndex] = $this->hasFourConnectedNeighbor(
                    $mask,
                    $width,
                    $height,
                    $x,
                    $y,
                    $pixelIndex,
                ) ? 1 : 0;
            }
        }

        return $result;
    }

    /**
     * Checks whether a pixel has a set four-connected neighbor.
     *
     * @param array<int, int> $mask Binary mask where 1 marks a set pixel.
     */
    private function hasFourConnectedNeighbor(
        array $mask,
        int $width,
        int $height,
        int $x,
        int $y,
        int $pixelIndex,
    ): bool {
        return (($x > 0) && ($mask[$pixelIndex - 1] === 1))
            || (($x < $width - 1) && ($mask[$pixelIndex + 1] === 1))
            || (($y > 0) && ($mask[$pixelIndex - $width] === 1))
            || (($y < $height - 1) && ($mask[$pixelIndex + $width] === 1));
    }

    /**
     * Finds connected components (spatially coherent regions of difference) via iterative flood-fill (BFS).
     *
     * This method identifies distinct 'blobs' of changed pixels and measures their size.
     * The largest blob area is a strong indicator of localized manual editing (e.g.,
     * blurring a face or license plate) versus global noise.
     *
     * @param array<int, int> $mask   Binary mask where 1 indicates a significant pixel difference.
     * @param int             $width  Width of the mask.
     * @param int             $height Height of the mask.
     *
     * @return array{int, int} Returns [blobCount, largestBlobArea].
     */
    private function findBlobs(array $mask, int $width, int $height): array
    {
        $visited         = [];
        $blobCount       = 0;
        $largestBlobArea = 0;
        $totalPixels     = $width * $height;

        for ($pixelIndex = 0; $pixelIndex < $totalPixels; ++$pixelIndex) {
            if ($mask[$pixelIndex] === 0) {
                continue;
            }

            if (isset($visited[$pixelIndex])) {
                continue;
            }

            // BFS flood-fill from this pixel.
            // Mark visited on enqueue (not dequeue) to prevent duplicate queue entries.
            $visited[$pixelIndex] = true;
            $queue                = [$pixelIndex];
            $area                 = 0;

            while ($queue !== []) {
                $currentIndex = array_pop($queue);
                ++$area;

                $x = $currentIndex % $width;
                $y = intdiv($currentIndex, $width);

                // 4-connected neighbors — only enqueue if not yet visited
                if ($x > 0) {
                    $neighborIndex = $currentIndex - 1;

                    if (!isset($visited[$neighborIndex]) && ($mask[$neighborIndex] === 1)) {
                        $visited[$neighborIndex] = true;
                        $queue[]                 = $neighborIndex;
                    }
                }

                if ($x < $width - 1) {
                    $neighborIndex = $currentIndex + 1;

                    if (!isset($visited[$neighborIndex]) && ($mask[$neighborIndex] === 1)) {
                        $visited[$neighborIndex] = true;
                        $queue[]                 = $neighborIndex;
                    }
                }

                if ($y > 0) {
                    $neighborIndex = $currentIndex - $width;

                    if (!isset($visited[$neighborIndex]) && ($mask[$neighborIndex] === 1)) {
                        $visited[$neighborIndex] = true;
                        $queue[]                 = $neighborIndex;
                    }
                }

                if ($y < $height - 1) {
                    $neighborIndex = $currentIndex + $width;

                    if (!isset($visited[$neighborIndex]) && ($mask[$neighborIndex] === 1)) {
                        $visited[$neighborIndex] = true;
                        $queue[]                 = $neighborIndex;
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
