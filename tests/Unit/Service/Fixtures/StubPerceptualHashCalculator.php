<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Fixtures;

use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use Override;
use SplFileInfo;

use function abs;
use function ctype_xdigit;
use function hex2bin;
use function min;
use function ord;
use function strlen;

/**
 * In-memory stub of PerceptualHashCalculatorInterface for unit tests.
 *
 * Allows pre-programming per-path dHash responses without requiring
 * real image files or ffmpeg.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class StubPerceptualHashCalculator implements PerceptualHashCalculatorInterface
{
    /**
     * @var array<string, string|null> pathname → dHash hex string
     */
    private array $hashes = [];

    /**
     * Pre-programs the dHash for a given file path.
     */
    public function withHash(string $pathname, ?string $dhash): self
    {
        $this->hashes[$pathname] = $dhash;

        return $this;
    }

    public function computeDhash(SplFileInfo $file): ?string
    {
        return $this->hashes[$file->getPathname()] ?? null;
    }

    /**
     * Computes similarity based on dHash Hamming distance only.
     * Uses simple threshold: distance ≤ 10 → score 95 (duplicate_likely),
     * otherwise proportional score.
     */
    #[Override]
    public function similarityScore(
        SplFileInfo $fileA,
        SplFileInfo $fileB,
        ?float $durationA = null,
        ?float $durationB = null,
    ): SimilarityResult {
        $dhashA = $this->computeDhash($fileA);
        $dhashB = $this->computeDhash($fileB);

        $dd = ($dhashA !== null && $dhashB !== null)
            ? $this->hammingDistance($dhashA, $dhashB)
            : 64;

        $score          = (int) round(max(0.0, 1.0 - ($dd / 64.0)) * 100);
        $classification = match (true) {
            $score >= 95 => SimilarityClassification::DuplicateLikely,
            $score >= 85 => SimilarityClassification::EditedVariant,
            default      => SimilarityClassification::Different,
        };

        return new SimilarityResult($score, $dd, 0, 0.0, 0.0, null, $classification);
    }

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
        $this->hashes = [];
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
