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
use Override;
use SplFileInfo;
use Throwable;

use function abs;
use function array_fill;
use function array_slice;
use function array_sum;
use function bindec;
use function count;
use function ctype_xdigit;
use function dechex;
use function hex2bin;
use function intdiv;
use function max;
use function min;
use function ord;
use function round;
use function sort;
use function str_repeat;
use function strlen;
use function strtolower;
use function substr;

use const SORT_NUMERIC;

/**
 * Multi-signal perceptual hash calculator for visual near-duplicate detection.
 *
 * Computes four visual signals from a single normalized Imagick instance:
 * - dHash (64-bit): horizontal brightness gradients on 9×8 grid
 * - wHash (64-bit): Haar wavelet texture hash on 32×32 grid
 * - HF-energy: high-frequency texture score (Gaussian blur difference)
 * - Color histogram: 3D RGB histogram L1 distance
 *
 * All signals use Imagick for pixel extraction with proper color space
 * normalization (autoOrient + sRGB), which is critical for JPG↔HEIC comparison.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class PerceptualHashCalculator implements PerceptualHashCalculatorInterface
{
    /**
     * Maximum decode resolution hint for hash computation.
     * JPEG decoder loads at nearest 1/2/4/8 that covers this size
     * instead of full resolution — ~10× faster for 3000+ px originals.
     */
    private const int HASH_DECODE_SIZE = 256;

    private const int DHASH_WIDTH = 9;

    private const int DHASH_HEIGHT = 8;

    private const int WHASH_SIZE = 32;

    private const float HF_BLUR_SIGMA = 1.2;

    private const int HIST_SIZE = 64;

    private const int HIST_BINS = 8;

    /**
     * In-memory cache for the current processing session.
     * Maps file pathnames to their loaded Imagick instances and computed dHash
     * values to avoid redundant image loading during pairwise comparisons within
     * the same group.
     *
     * @var array<string, array{dhash: string|null, img: Imagick|null}>
     */
    private array $loadCache = [];

    /**
     * Cache for disk-based signal hits to avoid any Imagick interaction.
     * Maps file pathnames to the full set of perceptual signals retrieved from
     * the persistent PerceptualSignalCache.
     *
     * @var array<string, array{dhash: string|null, whash: string|null, hf: float|null, hist: list<float>|null}>
     */
    private array $diskCacheHits = [];

    private ?PerceptualSignalCache $signalCache = null;

    /**
     * @param ImagickImageLoader $imageLoader Loader for normalized images and video frames.
     */
    public function __construct(
        private readonly ImagickImageLoader $imageLoader,
    ) {
    }

    /**
     * Injects the persistent disk cache for cross-run signal reuse.
     */
    public function setSignalCache(PerceptualSignalCache $signalCache): void
    {
        $this->signalCache = $signalCache;
    }

    /**
     * Loads and normalizes a file, computes dHash, and caches both for reuse.
     *
     * When the same file appears in multiple pairwise comparisons, the
     * Imagick instance and dHash are reused without redundant disk I/O.
     * If the persistent disk cache contains all signals, the image load is skipped entirely.
     *
     * @param SplFileInfo $file The file to process.
     *
     * @return array{Imagick|null, string|null} An array containing the Imagick instance (if loaded) and the dHash string.
     */
    private function loadAndComputeDhash(SplFileInfo $file): array
    {
        $pathname = $file->getPathname();

        // In-memory cache hit (pairwise reuse within group)
        if (isset($this->loadCache[$pathname])) {
            return [$this->loadCache[$pathname]['img'], $this->loadCache[$pathname]['dhash']];
        }

        // Disk cache hit — all signals available, no Imagick load needed
        if ($this->signalCache instanceof PerceptualSignalCache) {
            $cached = $this->signalCache->get($file);

            if ($cached !== null) {
                $this->diskCacheHits[$pathname] = $cached;
                $this->loadCache[$pathname]     = ['dhash' => $cached['dhash'], 'img' => null];

                return [null, $cached['dhash']];
            }
        }

        // Cache miss — load via Imagick
        $img   = $this->imageLoader->loadNormalized($file, self::HASH_DECODE_SIZE);
        $dhash = ($img instanceof Imagick) ? $this->computeDhashFromImage($img) : null;

        $this->loadCache[$pathname] = ['dhash' => $dhash, 'img' => $img];

        return [$img, $dhash];
    }

    /**
     * Computes dHash from an already-loaded normalized Imagick instance.
     *
     * The dHash (difference hash) captures visual gradients by comparing
     * adjacent pixel brightness on a small grid. It is extremely fast and robust
     * against aspect ratio changes.
     *
     * @param Imagick $img The normalized image.
     *
     * @return string|null The 64-bit hex dHash or null on failure.
     */
    private function computeDhashFromImage(Imagick $img): ?string
    {
        try {
            $gray = $this->grayscaleClone($img, self::DHASH_WIDTH, self::DHASH_HEIGHT);
            /** @var list<int> $pixels */
            $pixels = $gray->exportImagePixels(
                0,
                0,
                self::DHASH_WIDTH,
                self::DHASH_HEIGHT,
                'I',
                Imagick::PIXEL_CHAR,
            );
            $gray->clear();

            $bits = '';

            for ($y = 0; $y < self::DHASH_HEIGHT; ++$y) {
                for ($x = 0; $x < self::DHASH_HEIGHT; ++$x) {
                    $bits .= ($pixels[$y * self::DHASH_WIDTH + $x] > $pixels[$y * self::DHASH_WIDTH + $x + 1])
                        ? '1'
                        : '0';
                }
            }

            return strtolower($this->bitsToHex($bits, 64));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes wHash from an already-loaded normalized Imagick instance.
     *
     * The wHash (wavelet hash) uses a 2D Haar wavelet transform to capture
     * frequency information. It is more robust against rotations and shifts
     * than dHash but more computationally expensive.
     *
     * @param Imagick $img The normalized image.
     *
     * @return string|null The 64-bit hex wHash or null on failure.
     */
    private function computeWhashFromImage(Imagick $img): ?string
    {
        try {
            $gray   = $this->grayscaleClone($img, self::WHASH_SIZE, self::WHASH_SIZE);
            $pixels = $gray->exportImagePixels(0, 0, self::WHASH_SIZE, self::WHASH_SIZE, 'I', Imagick::PIXEL_DOUBLE);
            $gray->clear();

            $matrix = $this->pixelsToMatrix($pixels, self::WHASH_SIZE, self::WHASH_SIZE);

            $level1 = $this->haar2D($matrix);
            $ll1    = $this->topLeft($level1, self::WHASH_SIZE / 2);

            $level2 = $this->haar2D($ll1);
            $ll2    = $this->topLeft($level2, self::WHASH_SIZE / 4);

            $flat = [];

            foreach ($ll2 as $row) {
                foreach ($row as $value) {
                    $flat[] = $value;
                }
            }

            $sorted = $flat;
            sort($sorted, SORT_NUMERIC);
            $median = $sorted[count($sorted) >> 1];

            $bits = '';

            foreach ($flat as $value) {
                $bits .= ($value > $median) ? '1' : '0';
            }

            return strtolower($this->bitsToHex($bits, 64));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes color histogram from an already-resized Imagick instance.
     *
     * Generates a 3D RGB histogram with 8 bins per channel (512 total bins).
     * The histogram is normalized so that all bin values sum to 1.0.
     *
     * @param Imagick $resized The image resized to HIST_SIZE x HIST_SIZE.
     *
     * @return list<float>|null The normalized histogram bins or null on failure.
     */
    private function computeColorHistogramFromResized(Imagick $resized): ?array
    {
        try {
            /** @var list<int> $pixels */
            $pixels = $resized->exportImagePixels(
                0,
                0,
                $resized->getImageWidth(),
                $resized->getImageHeight(),
                'RGB',
                Imagick::PIXEL_CHAR,
            );

            $bins   = self::HIST_BINS;
            $binDiv = intdiv(256, $bins);
            $hist   = array_fill(0, $bins * $bins * $bins, 0.0);
            $count  = intdiv(count($pixels), 3);

            for ($pixelIndex = 0; $pixelIndex < $count; ++$pixelIndex) {
                $redBinIndex   = min($bins - 1, intdiv($pixels[$pixelIndex * 3], $binDiv));
                $greenBinIndex = min($bins - 1, intdiv($pixels[$pixelIndex * 3 + 1], $binDiv));
                $blueBinIndex  = min($bins - 1, intdiv($pixels[$pixelIndex * 3 + 2], $binDiv));

                $hist[($redBinIndex * $bins * $bins) + ($greenBinIndex * $bins) + $blueBinIndex] += 1.0;
            }

            $sum = array_sum($hist);

            if ($sum > 0.0) {
                foreach ($hist as $binIndex => $binValue) {
                    $hist[$binIndex] = $binValue / $sum;
                }
            }

            return array_values($hist);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes HF-energy from an already-resized grayscale Imagick instance.
     *
     * HF-energy (high-frequency energy) measures the amount of texture/detail
     * by calculating the mean absolute difference between the original and a
     * Gaussian-blurred version of the image.
     *
     * @param Imagick $gray The grayscale image resized to $size x $size.
     * @param int     $size The size (width/height) of the square image.
     *
     * @return float|null The mean absolute difference or null on failure.
     */
    private function computeHfEnergyFromGray(Imagick $gray, int $size): ?float
    {
        try {
            $original = $gray->exportImagePixels(0, 0, $size, $size, 'I', Imagick::PIXEL_DOUBLE);

            $blurred = clone $gray;
            $blurred->gaussianBlurImage(0.0, self::HF_BLUR_SIGMA);
            $smooth = $blurred->exportImagePixels(0, 0, $size, $size, 'I', Imagick::PIXEL_DOUBLE);
            $blurred->clear();

            $sum   = 0.0;
            $count = count($original);

            for ($pixelIndex = 0; $pixelIndex < $count; ++$pixelIndex) {
                $pixelValue  = $original[$pixelIndex] + 0.0;
                $smoothValue = $smooth[$pixelIndex] + 0.0;

                if ($pixelValue > 1.0) {
                    $pixelValue /= 65535.0;
                }

                if ($smoothValue > 1.0) {
                    $smoothValue /= 65535.0;
                }

                $sum += abs($pixelValue - $smoothValue);
            }

            return $sum / $count;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes the L1 distance between two normalized histograms (0.0–1.0).
     *
     * The L1 distance (Manhattan distance) is calculated as half the sum of
     * absolute differences between corresponding bins. For normalized histograms,
     * this results in a value between 0.0 (identical) and 1.0 (completely disjoint).
     *
     * @param list<float> $histA Normalized histogram A.
     * @param list<float> $histB Normalized histogram B.
     *
     * @return float The L1 distance.
     */
    private function histogramDistance(array $histA, array $histB): float
    {
        $sum     = 0.0;
        $numBins = min(count($histA), count($histB));

        for ($binIndex = 0; $binIndex < $numBins; ++$binIndex) {
            $sum += abs($histA[$binIndex] - $histB[$binIndex]);
        }

        return $sum / 2.0;
    }

    /**
     * Computes multi-signal similarity with early-exit optimization.
     *
     * Phase 1: Cheap dHash pre-filter (only 9×8 grayscale per file).
     * If dHash distance > 16 → clearly different → skip expensive signals.
     *
     * Phase 2: Full signal computation (wHash, HF-energy, color histogram)
     * only for pairs that pass the dHash pre-filter. Imagick instances are
     * cached per pathname for reuse across pairwise comparisons.
     */
    #[Override]
    public function similarityScore(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        ?float $durationA = null,
        ?float $durationB = null,
    ): SimilarityResult {
        // Phase 1: Load + dHash, cached per pathname for pairwise reuse
        [$imgA, $dhashA] = $this->loadAndComputeDhash($fileA);
        [$imgB, $dhashB] = $this->loadAndComputeDhash($fileB);
        $dhashDistance   = (($dhashA !== null) && ($dhashB !== null))
            ? $this->hammingDistance($dhashA, $dhashB)
            : 64;

        // Early exit: dHash distance > 16 → clearly different content.
        // Images stay in loadCache for potential reuse in other pairwise comparisons.
        if ($dhashDistance > 16) {
            $score = (int) round(max(0.0, 1.0 - ($dhashDistance / 64.0)) * 100);

            return new SimilarityResult($score, $dhashDistance, 64, 1.0, 1.0, null, SimilarityClassification::Different);
        }

        // Early exit: dHash = 0 AND not video → visually identical.
        $isVideo = (($durationA !== null) && ($durationB !== null));

        if (($dhashDistance === 0) && (!$isVideo)) {
            return new SimilarityResult(100, 0, 0, 0.0, 0.0, null, SimilarityClassification::DuplicateLikely);
        }

        // Phase 2: Use disk-cached signals if available, otherwise compute from Imagick
        $signalsA = $this->getOrComputeRemainingSignals($fileA, $imgA);
        $signalsB = $this->getOrComputeRemainingSignals($fileB, $imgB);

        $whashDistance = (($signalsA !== null) && ($signalsB !== null))
            ? $this->hammingDistance($signalsA['whash'] ?? '', $signalsB['whash'] ?? '')
            : 64;

        $hfEnergyDelta = (($signalsA !== null) && ($signalsB !== null) && ($signalsA['hf'] !== null) && ($signalsB['hf'] !== null))
            ? abs($signalsA['hf'] - $signalsB['hf'])
            : 1.0;

        $colorDistance = (($signalsA !== null) && ($signalsB !== null) && ($signalsA['hist'] !== null) && ($signalsB['hist'] !== null))
            ? $this->histogramDistance($signalsA['hist'], $signalsB['hist'])
            : 1.0;

        $durDelta = null;

        if ($isVideo) {
            $durDelta = abs($durationA - $durationB);
        }

        $score = $this->computeWeightedScore($dhashDistance, $whashDistance, $hfEnergyDelta, $colorDistance, $durDelta, $isVideo);

        $classification = match (true) {
            $score >= 95 => SimilarityClassification::DuplicateLikely,
            $score >= 85 => SimilarityClassification::EditedVariant,
            default      => SimilarityClassification::Different,
        };

        return new SimilarityResult($score, $dhashDistance, $whashDistance, $hfEnergyDelta, $colorDistance, $durDelta, $classification);
    }

    /**
     * Returns cached signals from disk or computes them from the Imagick instance.
     * Also persists newly computed signals to the disk cache for future runs.
     *
     * @return array{whash: string|null, hf: float|null, hist: list<float>|null}|null
     */
    private function getOrComputeRemainingSignals(SplFileInfo $file, ?Imagick $img): ?array
    {
        $pathname = $file->getPathname();

        // Disk cache hit with complete signals — return directly
        if (isset($this->diskCacheHits[$pathname])) {
            $cached = $this->diskCacheHits[$pathname];

            // Only use cache if it has full signals (not just dHash-only entries)
            if (($cached['whash'] !== null) || ($cached['hf'] !== null)) {
                return ['whash' => $cached['whash'], 'hf' => $cached['hf'], 'hist' => $cached['hist']];
            }

            // dHash-only cache entry — need to load image for remaining signals
            unset($this->diskCacheHits[$pathname]);
        }

        // Load image if not already available (disk cache had dHash-only entry)
        if (!$img instanceof Imagick) {
            $img = $this->imageLoader->loadNormalized($file, self::HASH_DECODE_SIZE);
        }

        if (!$img instanceof Imagick) {
            return null;
        }

        $signals = $this->computeRemainingSignals($img);

        // Persist to disk cache for future runs
        if (($signals !== null) && ($this->signalCache instanceof PerceptualSignalCache)) {
            $this->signalCache->set($file, [
                'dhash' => $this->loadCache[$pathname]['dhash'] ?? null,
                'whash' => $signals['whash'],
                'hf'    => $signals['hf'],
                'hist'  => $signals['hist'],
            ]);
        }

        return $signals;
    }

    /**
     * Computes wHash, HF-energy, and color histogram from an already-loaded image.
     * Shares a single 64×64 clone for HF-energy and histogram (both need similar
     * resolution), avoiding a redundant Imagick resize.
     *
     * @return array{whash: string|null, hf: float|null, hist: list<float>|null}|null
     */
    private function computeRemainingSignals(Imagick $img): ?array
    {
        try {
            // wHash uses 32×32 grayscale — separate clone
            $whash = $this->computeWhashFromImage($img);

            // HF-energy and histogram share a single 64×64 clone
            $shared = clone $img;
            $shared->resizeImage(self::HIST_SIZE, self::HIST_SIZE, Imagick::FILTER_TRIANGLE, 1.0);

            // Color histogram from the color clone
            $hist = $this->computeColorHistogramFromResized($shared);

            // Convert to grayscale in-place for HF-energy
            $shared->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            $hf = $this->computeHfEnergyFromGray($shared, self::HIST_SIZE);

            $shared->clear();

            return ['whash' => $whash, 'hf' => $hf, 'hist' => $hist];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes the weighted multi-signal similarity score (0–100).
     *
     * Combines multiple perceptual signals with specific weights to determine a
     * final similarity percentage. Weights differ for images and videos to
     * account for temporal consistency (duration) and codec-induced noise.
     *
     * Weights (Images): dHash 30%, wHash 25%, HF-energy 20%, color 25%.
     * Weights (Videos): dHash 25%, wHash 20%, HF-energy 15%, color 10%, duration 30%.
     *
     * @param int        $dhashDistance Hamming distance of dHash (0-64).
     * @param int        $whashDistance Hamming distance of wHash (0-64).
     * @param float      $hfEnergyDelta Absolute difference in HF-energy (0.0-1.0).
     * @param float      $colorDistance L1 distance of color histograms (0.0-1.0).
     * @param float|null $durDelta      Absolute duration difference in seconds (videos only).
     * @param bool       $isVideo       Whether the comparison is between video files.
     *
     * @return int The final similarity score as a percentage (0-100).
     */
    private function computeWeightedScore(
        int $dhashDistance,
        int $whashDistance,
        float $hfEnergyDelta,
        float $colorDistance,
        ?float $durDelta,
        bool $isVideo,
    ): int {
        $simDhash = max(0.0, 1.0 - ($dhashDistance / 64.0));
        $simWhash = max(0.0, 1.0 - ($whashDistance / 64.0));
        $simHf    = 1.0 - min(1.0, $hfEnergyDelta / 0.15);
        $simColor = 1.0 - min(1.0, $colorDistance);

        if ($isVideo && ($durDelta !== null)) {
            $simDur = max(0.0, 1.0 - $durDelta / 30.0);

            // For videos with matching duration (±1s), suppress unreliable color score.
            // Video re-encodes produce different pixel values per codec but identical duration.
            $videoColor = ($durDelta <= 1.0) ? 1.0 : $simColor;

            $score = 0.25 * $simDhash + 0.20 * $simWhash + 0.15 * $simHf + 0.10 * $videoColor + 0.30 * $simDur;
        } else {
            $score = 0.30 * $simDhash + 0.25 * $simWhash + 0.20 * $simHf + 0.25 * $simColor;
        }

        return (int) round($score * 100);
    }

    /**
     * Computes the Hamming distance between two hex hashes.
     *
     * The distance is calculated as the number of differing bits.
     * If lengths differ, the difference is added to the bit-level distance.
     *
     * @param string $hashA First hex hash.
     * @param string $hashB Second hex hash.
     *
     * @return int The Hamming distance in bits.
     */
    private function hammingDistance(string $hashA, string $hashB): int
    {
        $binA = $this->decodeHex($hashA);
        $binB = $this->decodeHex($hashB);

        if (($binA === null) || ($binB === null)) {
            return 64;
        }

        $len  = min(strlen($binA), strlen($binB));
        $dist = 0;

        for ($i = 0; $i < $len; ++$i) {
            $dist += $this->bitcount(ord($binA[$i]) ^ ord($binB[$i]));
        }

        return $dist + 8 * abs(strlen($binA) - strlen($binB));
    }

    #[Override]
    public function clearCache(): void
    {
        // Cache dHash-only results to disk for files that hit the dHash=0 early exit
        // (these never reach Phase 2 so getOrComputeRemainingSignals doesn't cache them)
        if ($this->signalCache instanceof PerceptualSignalCache) {
            foreach ($this->loadCache as $pathname => $entry) {
                if (!isset($this->diskCacheHits[$pathname]) && ($entry['dhash'] !== null)) {
                    $this->signalCache->set(
                        new SplFileInfo($pathname),
                        ['dhash' => $entry['dhash'], 'whash' => null, 'hf' => null, 'hist' => null],
                    );
                }
            }
        }

        foreach ($this->loadCache as $entry) {
            $entry['img']?->clear();
        }

        $this->loadCache     = [];
        $this->diskCacheHits = [];
    }

    /**
     * Creates a grayscale, resized clone of the given Imagick instance.
     *
     * Used as a pre-processing step for various perceptual hashing algorithms
     * to reduce noise and normalize the input for faster computation.
     *
     * @param Imagick $source The original image instance to clone and process.
     * @param int     $width  The target width for resizing.
     * @param int     $height The target height for resizing.
     *
     * @return Imagick A new, processed Imagick instance.
     */
    private function grayscaleClone(Imagick $source, int $width, int $height): Imagick
    {
        $img = clone $source;
        $img->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1.0);
        $img->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        return $img;
    }

    /**
     * Converts a flat pixel array to a 2D matrix, normalizing values to 0.0–1.0.
     *
     * Supports both 8-bit (0-255) and 16-bit (0-65535) integer input values,
     * mapping them to a consistent float range for mathematical transforms.
     *
     * @param list<int> $pixels Flat pixel values from Imagick::exportImagePixels().
     * @param int       $width  Width of the image in pixels.
     * @param int       $height Height of the image in pixels.
     *
     * @return array<int, array<int, float>> A 2D matrix of normalized floats.
     */
    private function pixelsToMatrix(array $pixels, int $width, int $height): array
    {
        $matrix = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = [];

            for ($x = 0; $x < $width; ++$x) {
                $pixelValue = $pixels[$y * $width + $x] + 0.0;

                if ($pixelValue > 1.0) {
                    $pixelValue /= 65535.0;
                }

                $row[] = max(0.0, min(1.0, $pixelValue));
            }

            $matrix[] = $row;
        }

        return $matrix;
    }

    /**
     * Performs a single level of 2D Haar wavelet transform on the input matrix.
     *
     * The transform is applied first to rows, then to columns, decomposing the
     * image into four sub-bands: LL (averages), LH, HL, and HH (details).
     *
     * @param array<int, array<int, float>> $matrix The input 2D image matrix.
     *
     * @return array<int, array<int, float>> The transformed 2D matrix.
     */
    private function haar2D(array $matrix): array
    {
        $height = count($matrix);
        $width  = count($matrix[0]);

        // Transform rows
        $transformedRows = [];

        for ($y = 0; $y < $height; ++$y) {
            $transformedRows[$y] = $this->haar1D($matrix[$y]);
        }

        // Transform columns
        $transformedMatrix = array_fill(0, $height, array_fill(0, $width, 0.0));

        for ($x = 0; $x < $width; ++$x) {
            $columnValues = [];

            for ($y = 0; $y < $height; ++$y) {
                $columnValues[] = $transformedRows[$y][$x];
            }

            $colTransformed = $this->haar1D($columnValues);

            for ($y = 0; $y < $height; ++$y) {
                $transformedMatrix[$y][$x] = $colTransformed[$y];
            }
        }

        return $transformedMatrix;
    }

    /**
     * Performs a 1D Haar wavelet transform on an array of values.
     *
     * Computes pairwise sums (averages) and differences (details), effectively
     * separating the signal into low and high frequency components.
     *
     * @param array<int, float> $values The input signal array.
     *
     * @return array<int, float> The transformed signal (averages in first half, details in second).
     */
    private function haar1D(array $values): array
    {
        $numValues = count($values);
        $halfSize  = intdiv($numValues, 2);
        $output    = array_fill(0, $numValues, 0.0);

        for ($index = 0; $index < $halfSize; ++$index) {
            $valueA = $values[2 * $index];
            $valueB = $values[2 * $index + 1];

            $output[$index]             = ($valueA + $valueB) / 2.0;
            $output[$halfSize + $index] = ($valueA - $valueB) / 2.0;
        }

        return $output;
    }

    /**
     * Extracts the top-left square submatrix of a given size.
     *
     * Used in wavelet transforms (wHash) to extract the lowest frequency components
     * after multiple transform passes, effectively capturing the most stable image features.
     *
     * @param array<int, array<int, float>> $matrix Input 2D matrix (usually from Haar/DCT).
     * @param int                           $size   The dimension of the square to extract.
     *
     * @return array<int, array<int, float>> The $size x $size submatrix.
     */
    private function topLeft(array $matrix, int $size): array
    {
        $subMatrix = [];

        for ($y = 0; $y < $size; ++$y) {
            $subMatrix[] = array_slice($matrix[$y], 0, $size);
        }

        return $subMatrix;
    }

    /**
     * Converts a bit string to a hex string with safe padding and optional fixed width.
     *
     * Ensures the bit string length is a multiple of 4 (nibble-aligned) by prepending
     * zero-bits if necessary, then converts each 4-bit chunk to its hexadecimal representation.
     *
     * @param string   $bits       The string of '0' and '1' characters to convert.
     * @param int|null $targetBits Optional target bit length to pad to before nibble alignment.
     *
     * @return string The resulting lowercase hex string.
     */
    private function bitsToHex(string $bits, ?int $targetBits = null): string
    {
        $len = strlen($bits);

        if (($targetBits !== null) && ($targetBits > $len)) {
            $bits = str_repeat('0', $targetBits - $len) . $bits;
            $len  = $targetBits;
        }

        $padBits = (4 - ($len % 4)) % 4;

        if ($padBits > 0) {
            $bits = str_repeat('0', $padBits) . $bits;
            $len += $padBits;
        }

        $hex = '';

        for ($i = 0; $i < $len; $i += 4) {
            $chunk = substr($bits, $i, 4);
            $hex .= dechex((int) bindec($chunk));
        }

        return $hex;
    }

    /**
     * Decodes a hex string to its binary representation safely.
     *
     * Performs strict validation for hex characters and even string length
     * to prevent errors during binary conversion.
     *
     * @param string $value The hex string to decode.
     *
     * @return string|null The binary string or null if invalid hex or odd length.
     */
    private function decodeHex(string $value): ?string
    {
        if ((strlen($value) & 1) === 1) {
            return null;
        }

        if (($value !== '') && !ctype_xdigit($value)) {
            return null;
        }

        $decoded = hex2bin($value);

        return $decoded !== false ? $decoded : null;
    }

    /**
     * Returns the number of set bits (population count) in an integer.
     *
     * Efficiently calculates the bit count by iteratively clearing the
     * least significant set bit using the `$value & ($value - 1)` trick.
     *
     * @param int $value The integer to check.
     *
     * @return int The number of bits set to 1.
     */
    private function bitcount(int $value): int
    {
        $count = 0;

        while ($value !== 0) {
            $value &= $value - 1;
            ++$count;
        }

        return $count;
    }
}
