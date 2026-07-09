<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashMath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies low-level perceptual hash math helpers.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PerceptualHashMath::class)]
final class PerceptualHashMathTest extends TestCase
{
    /**
     * Verifies bit packing for short and fixed-width bit strings.
     */
    #[Test]
    public function bitsToHexPadsToNibbleAndTargetWidth(): void
    {
        $math = new PerceptualHashMath();

        self::assertSame('5', $math->bitsToHex('101'));
        self::assertSame('05', $math->bitsToHex('101', 8));
        self::assertSame('0005', $math->bitsToHex('101', 16));
    }

    /**
     * Verifies strict hex decoding behavior through the public distance contract.
     */
    #[Test]
    public function hammingDistanceRejectsInvalidHexAndCountsUnequalLengths(): void
    {
        $math = new PerceptualHashMath();

        self::assertSame(64, $math->hammingDistance('abc', '00'));
        self::assertSame(64, $math->hammingDistance('zz', '00'));
        self::assertSame(16, $math->hammingDistance('ff', '00ff'));
        self::assertSame(4, $math->hammingDistance('0f', '00'));
    }

    /**
     * Verifies score weighting at image/video boundaries, including the video
     * color-noise suppression window for near-identical durations.
     */
    #[Test]
    public function weightedScoreHandlesImageAndVideoDurationBoundaries(): void
    {
        $math = new PerceptualHashMath();

        self::assertSame(74, $math->computeWeightedScore(8, 16, 0.03, 0.50, null, false));
        self::assertSame(90, $math->computeWeightedScore(8, 8, 0.03, 1.00, 1.0, true));
        self::assertSame(51, $math->computeWeightedScore(8, 8, 0.03, 1.00, 31.0, true));
    }

    /**
     * Verifies the population count helper for zero and multi-bit values.
     */
    #[Test]
    public function bitcountCountsOnlySetBits(): void
    {
        $math = new PerceptualHashMath();

        self::assertSame(0, $math->bitcount(0));
        self::assertSame(4, $math->bitcount(0b10101010));
        self::assertSame(8, $math->bitcount(0xFF));
    }
}
