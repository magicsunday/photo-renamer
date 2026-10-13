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
use function max;
use function min;
use function round;
use function sqrt;

/**
 * Measures grayscale RMSE and mean chroma difference for still-image Stage B.
 *
 * A bounded working resolution preserves the existing threshold calibration.
 * Analysis operates on clones and reports unavailable evidence separately from
 * a measured perfect match. Aggregate metrics do not locate individual edits.
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
     * Computes the grayscale Root Mean Square Error (RMSE) and the RGB chroma
     * difference between two images for the mandatory still-image Stage B.
     *
     * The classifier interprets these aggregate measurements against its safe
     * thresholds; this service reports failure instead of supplying match evidence.
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
            [$grayA, $grayB, $totalPixels, $chromaDiff] = $this->exportPixelData($imageA, $imageB);

            $rmse = $this->computeGrayscaleRmse($grayA, $grayB, $totalPixels);

            return new LocalDiffResult($rmse, chromaDifference: $chromaDiff);
        } catch (Throwable) {
            return new LocalDiffResult(0.0, success: false);
        }
    }

    /**
     * Preserves the existing analysis entry point with the same RMSE/chroma
     * measurements and availability flag as analyzeRmse().
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
     * @return array{list<int>, list<int>, int, float} A tuple containing:
     *                                                 - grayA: Grayscale pixels of image A
     *                                                 - grayB: Grayscale pixels of image B
     *                                                 - totalPixels: Total number of pixels
     *                                                 - chromaDiff: Computed chroma difference
     */
    private function exportPixelData(Imagick $imageA, Imagick $imageB): array
    {
        $scaledA = $this->downscale($imageA);
        $scaledB = null;

        try {
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

            return [$grayA, $grayB, $totalPixels, $chromaDiff];
        } finally {
            $scaledA->clear();
            $scaledB?->clear();
        }
    }

    /**
     * Computes RMSE normalized to 0.0–1.0 from two grayscale pixel arrays.
     * Operates on Imagick COLORSPACE_GRAY output to preserve calibrated thresholds.
     *
     * @param list<int> $pixelsA     Grayscale pixel values (1 per pixel)
     * @param list<int> $pixelsB     Grayscale pixel values (1 per pixel)
     * @param int       $totalPixels Number of aligned working pixels used for normalization.
     *
     * @return float Normalized grayscale RMSE; threshold interpretation belongs to the classifier.
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
     * @param list<int> $pixelsA     RGB pixel values (3 per pixel)
     * @param list<int> $pixelsB     RGB pixel values (3 per pixel)
     * @param int       $totalPixels Number of aligned working pixels used for averaging.
     *
     * @return float Normalized difference in mean chroma energy.
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
     * Downscales the given image to a working resolution (max WORK_SIZE)
     * while preserving the original colorspace and aspect ratio.
     *
     * The fixed maximum dimension bounds exported pixel arrays and preserves
     * the working scale used to calibrate the classifier thresholds.
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
}
