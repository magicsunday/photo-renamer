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

    private const int HF_SIZE = 128;

    private const float HF_BLUR_SIGMA = 1.2;

    private const int HIST_SIZE = 128;

    private const int HIST_BINS = 16;

    /**
     * In-memory cache: pathname → dHash hex string (or null on failure).
     *
     * @var array<string, string|null>
     */
    private array $dhashCache = [];

    public function __construct(
        private readonly ImagickImageLoaderInterface $imageLoader,
    ) {
    }

    #[Override]
    public function computeDhash(SplFileInfo $file): ?string
    {
        $pathname = $file->getPathname();

        if (isset($this->dhashCache[$pathname])) {
            return $this->dhashCache[$pathname];
        }

        // Use reduced decode resolution — only need 9×8 pixels
        $img = $this->imageLoader->loadNormalized($file, self::HASH_DECODE_SIZE);

        if (!$img instanceof Imagick) {
            $this->dhashCache[$pathname] = null;

            return null;
        }

        try {
            $hash                        = $this->computeDhashFromImage($img);
            $this->dhashCache[$pathname] = $hash;

            return $hash;
        } catch (Throwable) {
            $this->dhashCache[$pathname] = null;

            return null;
        } finally {
            $img->destroy();
        }
    }

    /**
     * Computes dHash from an already-loaded normalized Imagick instance.
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
            $gray->destroy();

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
     * Computes a 64-bit Haar wavelet hash (wHash).
     * Sensitive to mid-frequency texture changes (skin smoothing, noise reduction,
     * color grading) that dHash misses.
     *
     * Algorithm: 32×32 grayscale → 2-level Haar transform → 8×8 LL subband →
     * median threshold → 64-bit hash.
     */
    public function computeWhash(SplFileInfo $file): ?string
    {
        $img = $this->imageLoader->loadNormalized($file, self::HASH_DECODE_SIZE);

        if (!$img instanceof Imagick) {
            return null;
        }

        try {
            return $this->computeWhashFromImage($img);
        } finally {
            $img->destroy();
        }
    }

    /**
     * Computes the high-frequency energy score of an image.
     * Higher values indicate more texture/noise. Retouched images (skin smoothing,
     * denoising) have measurably less HF-energy than originals.
     */
    public function computeHfEnergy(SplFileInfo $file): ?float
    {
        $img = $this->imageLoader->loadNormalized($file, self::HASH_DECODE_SIZE);

        if (!$img instanceof Imagick) {
            return null;
        }

        try {
            return $this->computeHfEnergyFromImage($img);
        } finally {
            $img->destroy();
        }
    }

    /**
     * Computes a normalized 3D RGB color histogram.
     *
     * @return list<float>|null Normalized histogram (sum = 1.0), or null on failure
     */
    public function computeColorHistogram(SplFileInfo $file): ?array
    {
        $img = $this->imageLoader->loadNormalized($file, self::HASH_DECODE_SIZE);

        if (!$img instanceof Imagick) {
            return null;
        }

        try {
            return $this->computeColorHistogramFromImage($img);
        } finally {
            $img->destroy();
        }
    }

    /**
     * Computes wHash from an already-loaded normalized Imagick instance.
     */
    private function computeWhashFromImage(Imagick $img): ?string
    {
        try {
            $gray   = $this->grayscaleClone($img, self::WHASH_SIZE, self::WHASH_SIZE);
            $pixels = $gray->exportImagePixels(0, 0, self::WHASH_SIZE, self::WHASH_SIZE, 'I', Imagick::PIXEL_DOUBLE);
            $gray->destroy();

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
     * Computes HF-energy from an already-loaded normalized Imagick instance.
     */
    private function computeHfEnergyFromImage(Imagick $img): ?float
    {
        try {
            $gray     = $this->grayscaleClone($img, self::HF_SIZE, self::HF_SIZE);
            $original = $gray->exportImagePixels(0, 0, self::HF_SIZE, self::HF_SIZE, 'I', Imagick::PIXEL_DOUBLE);

            $blurred = clone $gray;
            $blurred->gaussianBlurImage(0.0, self::HF_BLUR_SIGMA);
            $smooth = $blurred->exportImagePixels(0, 0, self::HF_SIZE, self::HF_SIZE, 'I', Imagick::PIXEL_DOUBLE);

            $gray->destroy();
            $blurred->destroy();

            $sum   = 0.0;
            $count = count($original);

            for ($i = 0; $i < $count; ++$i) {
                $v = $original[$i] + 0.0;
                $s = $smooth[$i] + 0.0;

                if ($v > 1.0) {
                    $v /= 65535.0;
                }

                if ($s > 1.0) {
                    $s /= 65535.0;
                }

                $sum += abs($v - $s);
            }

            return $sum / $count;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes color histogram from an already-loaded normalized Imagick instance.
     *
     * @return list<float>|null
     */
    private function computeColorHistogramFromImage(Imagick $img): ?array
    {
        try {
            $resized = clone $img;
            $resized->resizeImage(self::HIST_SIZE, self::HIST_SIZE, Imagick::FILTER_LANCZOS, 1.0, false);

            /** @var list<int> $pixels */
            $pixels = $resized->exportImagePixels(0, 0, self::HIST_SIZE, self::HIST_SIZE, 'RGB', Imagick::PIXEL_CHAR);
            $resized->destroy();

            $bins   = self::HIST_BINS;
            $binDiv = intdiv(256, $bins);
            $hist   = array_fill(0, $bins * $bins * $bins, 0.0);
            $count  = intdiv(count($pixels), 3);

            for ($i = 0; $i < $count; ++$i) {
                $rb = min($bins - 1, intdiv($pixels[$i * 3], $binDiv));
                $gb = min($bins - 1, intdiv($pixels[$i * 3 + 1], $binDiv));
                $bb = min($bins - 1, intdiv($pixels[$i * 3 + 2], $binDiv));

                $hist[($rb * $bins * $bins) + ($gb * $bins) + $bb] += 1.0;
            }

            $sum = array_sum($hist);

            if ($sum > 0.0) {
                foreach ($hist as $k => $v) {
                    $hist[$k] = $v / $sum;
                }
            }

            return array_values($hist);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Computes the L1 distance between two normalized histograms (0.0–1.0).
     *
     * @param list<float> $a Normalized histogram A
     * @param list<float> $b Normalized histogram B
     */
    public function histogramDistance(array $a, array $b): float
    {
        $sum = 0.0;
        $n   = min(count($a), count($b));

        for ($i = 0; $i < $n; ++$i) {
            $sum += abs($a[$i] - $b[$i]);
        }

        return $sum / 2.0;
    }

    /**
     * Computes multi-signal similarity with early-exit optimization.
     *
     * Phase 1: Cheap dHash pre-filter (only 9×8 grayscale per file).
     * If dHash distance > 20 → clearly different → skip expensive signals.
     *
     * Phase 2: Full signal computation (wHash, HF-energy, color histogram)
     * only for pairs that pass the dHash pre-filter.
     */
    #[Override]
    public function similarityScore(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        ?float $durationA = null,
        ?float $durationB = null,
    ): SimilarityResult {
        // Phase 1: Cheap dHash pre-filter
        $dhashA = $this->computeDhash($fileA);
        $dhashB = $this->computeDhash($fileB);
        $dd     = ($dhashA !== null && $dhashB !== null)
            ? $this->hammingDistance($dhashA, $dhashB)
            : 64;

        // Early exit: dHash distance > 20 → clearly different content.
        // No need for expensive wHash/HF/color computation.
        if ($dd > 20) {
            $score = (int) round(max(0.0, 1.0 - ($dd / 64.0)) * 100);

            return new SimilarityResult($score, $dd, 64, 1.0, 1.0, null, 'different');
        }

        // Early exit: dHash = 0 AND not video → visually identical.
        // Skip wHash/HF/color — they can only confirm what dHash already shows.
        $isVideo = ($durationA !== null && $durationB !== null);

        if ($dd === 0 && !$isVideo) {
            return new SimilarityResult(100, 0, 0, 0.0, 0.0, null, 'duplicate_likely');
        }

        // Phase 2: Full signal computation (single Imagick load per file)
        $signalsA = $this->computeAllSignals($fileA);
        $signalsB = $this->computeAllSignals($fileB);

        // Use cached dHash from Phase 1 instead of recomputing
        $wd = ($signalsA['whash'] !== null && $signalsB['whash'] !== null)
            ? $this->hammingDistance($signalsA['whash'], $signalsB['whash'])
            : 64;

        $hfd = ($signalsA['hf'] !== null && $signalsB['hf'] !== null)
            ? abs($signalsA['hf'] - $signalsB['hf'])
            : 1.0;

        $cd = ($signalsA['hist'] !== null && $signalsB['hist'] !== null)
            ? $this->histogramDistance($signalsA['hist'], $signalsB['hist'])
            : 1.0;

        $durDelta = null;

        if ($isVideo) {
            $durDelta = abs($durationA - $durationB);
        }

        $score = $this->computeWeightedScore($dd, $wd, $hfd, $cd, $durDelta, $isVideo);

        $classification = match (true) {
            $score >= 95 => 'duplicate_likely',
            $score >= 85 => 'edited_variant',
            default      => 'different',
        };

        return new SimilarityResult($score, $dd, $wd, $hfd, $cd, $durDelta, $classification);
    }

    /**
     * Computes all perceptual signals from a single Imagick load.
     * One loadNormalized() call → dHash + wHash + HF-energy + color histogram.
     *
     * @return array{dhash: string|null, whash: string|null, hf: float|null, hist: list<float>|null}
     */
    private function computeAllSignals(SplFileInfo $file): array
    {
        $null = ['dhash' => null, 'whash' => null, 'hf' => null, 'hist' => null];

        // Reduced decode resolution — max signal needs 128×128 (HF-energy)
        $img = $this->imageLoader->loadNormalized($file, self::HASH_DECODE_SIZE);

        if (!$img instanceof Imagick) {
            return $null;
        }

        try {
            return [
                'dhash' => $this->computeDhashFromImage($img),
                'whash' => $this->computeWhashFromImage($img),
                'hf'    => $this->computeHfEnergyFromImage($img),
                'hist'  => $this->computeColorHistogramFromImage($img),
            ];
        } catch (Throwable) {
            return $null;
        } finally {
            $img->destroy();
        }
    }

    /**
     * Computes the weighted multi-signal score (0–100).
     *
     * For images: dHash 30%, wHash 25%, HF-energy 20%, color 25%.
     * For videos: dHash 25%, wHash 20%, HF-energy 15%, color 10%, duration 30%.
     */
    private function computeWeightedScore(
        int $dd,
        int $wd,
        float $hfd,
        float $cd,
        ?float $durDelta,
        bool $isVideo,
    ): int {
        $simDhash = max(0.0, 1.0 - ($dd / 64.0));
        $simWhash = max(0.0, 1.0 - ($wd / 64.0));
        $simHf    = 1.0 - min(1.0, $hfd / 0.15);
        $simColor = 1.0 - min(1.0, $cd);

        if ($isVideo && $durDelta !== null) {
            $simDur = max(0.0, 1.0 - $durDelta / 30.0);
            $score  = 0.25 * $simDhash + 0.20 * $simWhash + 0.15 * $simHf + 0.10 * $simColor + 0.30 * $simDur;
        } else {
            $score = 0.30 * $simDhash + 0.25 * $simWhash + 0.20 * $simHf + 0.25 * $simColor;
        }

        return (int) round($score * 100);
    }

    #[Override]
    public function hammingDistance(string $hashA, string $hashB): int
    {
        $binA = $this->decodeHex($hashA);
        $binB = $this->decodeHex($hashB);

        if ($binA === null || $binB === null) {
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
        $this->dhashCache = [];
    }

    /**
     * Creates a grayscale, resized clone of the given Imagick instance.
     */
    private function grayscaleClone(Imagick $source, int $width, int $height): Imagick
    {
        $img = clone $source;
        $img->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1.0, false);
        $img->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        return $img;
    }

    /**
     * Converts a flat pixel array to a 2D matrix, normalizing values to 0.0–1.0.
     *
     * @param list<int> $pixels Flat pixel values from Imagick::exportImagePixels()
     *
     * @return array<int, array<int, float>>
     */
    private function pixelsToMatrix(array $pixels, int $width, int $height): array
    {
        $matrix = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = [];

            for ($x = 0; $x < $width; ++$x) {
                $v = $pixels[$y * $width + $x] + 0.0;

                if ($v > 1.0) {
                    $v /= 65535.0;
                }

                $row[] = max(0.0, min(1.0, $v));
            }

            $matrix[] = $row;
        }

        return $matrix;
    }

    /**
     * 2D Haar wavelet transform (one level).
     *
     * @param array<int, array<int, float>> $matrix
     *
     * @return array<int, array<int, float>>
     */
    private function haar2D(array $matrix): array
    {
        $height = count($matrix);
        $width  = count($matrix[0]);

        // Transform rows
        $tmp = [];

        for ($y = 0; $y < $height; ++$y) {
            $tmp[$y] = $this->haar1D($matrix[$y]);
        }

        // Transform columns
        $out = array_fill(0, $height, array_fill(0, $width, 0.0));

        for ($x = 0; $x < $width; ++$x) {
            $col = [];

            for ($y = 0; $y < $height; ++$y) {
                $col[] = $tmp[$y][$x];
            }

            $colTransformed = $this->haar1D($col);

            for ($y = 0; $y < $height; ++$y) {
                $out[$y][$x] = $colTransformed[$y];
            }
        }

        return $out;
    }

    /**
     * 1D Haar wavelet transform: pairs → average + difference.
     *
     * @param array<int, float> $values
     *
     * @return array<int, float>
     */
    private function haar1D(array $values): array
    {
        $n    = count($values);
        $half = intdiv($n, 2);
        $out  = array_fill(0, $n, 0.0);

        for ($i = 0; $i < $half; ++$i) {
            $a = $values[2 * $i];
            $b = $values[2 * $i + 1];

            $out[$i]         = ($a + $b) / 2.0;
            $out[$half + $i] = ($a - $b) / 2.0;
        }

        return $out;
    }

    /**
     * Extracts the top-left square submatrix.
     *
     * @param array<int, array<int, float>> $matrix
     *
     * @return array<int, array<int, float>>
     */
    private function topLeft(array $matrix, int $size): array
    {
        $out = [];

        for ($y = 0; $y < $size; ++$y) {
            $out[] = array_slice($matrix[$y], 0, $size);
        }

        return $out;
    }

    /**
     * Converts a bit string to a hex string with safe padding.
     */
    private function bitsToHex(string $bits, ?int $targetBits = null): string
    {
        $len = strlen($bits);

        if ($targetBits !== null && $targetBits > $len) {
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

    private function decodeHex(string $value): ?string
    {
        if ((strlen($value) & 1) === 1) {
            return null;
        }

        if ($value !== '' && !ctype_xdigit($value)) {
            return null;
        }

        $decoded = hex2bin($value);

        return $decoded !== false ? $decoded : null;
    }

    private function bitcount(int $v): int
    {
        $c = 0;

        while ($v !== 0) {
            $v &= $v - 1;
            ++$c;
        }

        return $c;
    }
}
