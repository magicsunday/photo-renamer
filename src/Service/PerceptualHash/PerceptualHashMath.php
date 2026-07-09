<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use function abs;
use function bindec;
use function ctype_xdigit;
use function dechex;
use function hex2bin;
use function max;
use function min;
use function ord;
use function round;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Low-level math helpers for perceptual hash scoring.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class PerceptualHashMath
{
    public function computeWeightedScore(
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

            // Video re-encodes may change pixel values while preserving duration.
            $videoColor = ($durDelta <= 1.0) ? 1.0 : $simColor;

            $score = 0.25 * $simDhash + 0.20 * $simWhash + 0.15 * $simHf + 0.10 * $videoColor + 0.30 * $simDur;
        } else {
            $score = 0.30 * $simDhash + 0.25 * $simWhash + 0.20 * $simHf + 0.25 * $simColor;
        }

        return (int) round($score * 100);
    }

    public function hammingDistance(string $hashA, string $hashB): int
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

    public function bitsToHex(string $bits, ?int $targetBits = null): string
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

    public function bitcount(int $value): int
    {
        $count = 0;

        while ($value !== 0) {
            $value &= $value - 1;
            ++$count;
        }

        return $count;
    }

    private function decodeHex(string $value): ?string
    {
        if (($value === '') || ((strlen($value) & 1) === 1)) {
            return null;
        }

        if (!ctype_xdigit($value)) {
            return null;
        }

        $decoded = hex2bin($value);

        return $decoded !== false ? $decoded : null;
    }
}
