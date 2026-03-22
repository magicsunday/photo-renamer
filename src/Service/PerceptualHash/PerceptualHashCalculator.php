<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use Override;
use SplFileInfo;

use function abs;
use function bindec;
use function ctype_xdigit;
use function dechex;
use function hex2bin;
use function min;
use function ord;
use function str_repeat;
use function strlen;
use function strtolower;
use function substr;

/**
 * Computes 64-bit difference hashes (dHash) for visual near-duplicate detection.
 * The dHash algorithm compares horizontal brightness gradients in a 9×8 downsampled
 * grayscale image, producing a compact fingerprint that is robust against resizing,
 * compression artifacts, and format conversions.
 *
 * Ported from magicsunday/photo-memories PerceptualHashExtractor.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class PerceptualHashCalculator implements PerceptualHashCalculatorInterface
{
    /**
     * dHash uses a 9×8 sample (9 columns for 8 horizontal differences per row).
     */
    private const int DHASH_WIDTH = 9;

    private const int DHASH_HEIGHT = 8;

    /**
     * In-memory cache: pathname → dHash hex string (or null on failure).
     *
     * @var array<string, string|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly FfmpegGrayscaleLoaderInterface $loader,
    ) {
    }

    #[Override]
    public function computeDhash(SplFileInfo $file): ?string
    {
        $pathname = $file->getPathname();

        if (isset($this->cache[$pathname])) {
            return $this->cache[$pathname];
        }

        $matrix = $this->loader->loadGrayscaleMatrix($file, self::DHASH_WIDTH, self::DHASH_HEIGHT);

        if ($matrix === null) {
            $this->cache[$pathname] = null;

            return null;
        }

        $hash                   = $this->computeDhash64($matrix);
        $this->cache[$pathname] = $hash;

        return $hash;
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
        $this->cache = [];
    }

    /**
     * dHash 64-bit (horizontal): compare adjacent pixels in each row.
     *
     * @param array<int, array<int, float>> $matrix 9×8 grayscale matrix
     */
    private function computeDhash64(array $matrix): string
    {
        $bits = '';

        for ($y = 0; $y < self::DHASH_HEIGHT; ++$y) {
            for ($x = 0; $x < self::DHASH_HEIGHT; ++$x) {
                $bits .= ($matrix[$y][$x] > $matrix[$y][$x + 1]) ? '1' : '0';
            }
        }

        return strtolower($this->bitsToHex($bits, 64));
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

    /**
     * Decodes a hexadecimal string to binary representation.
     */
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

    /**
     * Counts the number of set bits in a byte.
     */
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
