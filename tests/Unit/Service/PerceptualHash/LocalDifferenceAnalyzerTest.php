<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDiffResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LocalDifferenceAnalyzer::class)]
#[UsesClass(LocalDiffResult::class)]
final class LocalDifferenceAnalyzerTest extends TestCase
{
    private LocalDifferenceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new LocalDifferenceAnalyzer();
    }

    /**
     * Ensures that identical images produce no differences.
     * RMSE, changed area, and blob count must be exactly 0.
     */
    #[Test]
    public function identicalImagesProduceZeroChanges(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'red');
        $imgB = $this->createSolidImage(64, 64, 'red');

        $result = $this->analyzer->analyze($imgA, $imgB);

        self::assertSame(0.0, $result->rmse);
        self::assertSame(0.0, $result->changedAreaRatio);
        self::assertSame(0.0, $result->largestBlobRatio);
        self::assertSame(0, $result->blobCount);
        self::assertFalse($result->hasCompactRetouch);
    }

    /**
     * Verifies that completely different images (white vs. black) produce a
     * high Root Mean Square Error (RMSE) value.
     */
    #[Test]
    public function completelyDifferentImagesProduceHighRmse(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'white');
        $imgB = $this->createSolidImage(64, 64, 'black');

        $result = $this->analyzer->analyze($imgA, $imgB);

        self::assertGreaterThan(0.5, $result->rmse);
    }

    /**
     * Checks if a single differing pixel results only in a minimal,
     * negligible RMSE value.
     */
    #[Test]
    public function singlePixelDifferenceProducesNearZeroRmse(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'white');
        $imgB = $this->createSolidImage(64, 64, 'white');

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));
        $draw->point(32, 32);

        $imgB->drawImage($draw);

        $result = $this->analyzer->analyze($imgA, $imgB);

        // One pixel out of 64×64 = negligible RMSE
        self::assertLessThan(0.02, $result->rmse);
    }

    /**
     * Simulates a local retouch (10x10 square) and checks if the RMSE is in a
     * moderate range that signals a retouch without the image being considered
     * completely different.
     */
    #[Test]
    public function localRetouchProducesModerateRmse(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'white');
        $imgB = $this->createSolidImage(64, 64, 'white');

        // Draw a 10x10 black square to simulate a local retouch
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));
        $draw->rectangle(20, 20, 30, 30);

        $imgB->drawImage($draw);

        $result = $this->analyzer->analyze($imgA, $imgB);

        // 10×10 black square on white → noticeable but not extreme RMSE
        self::assertGreaterThan(0.05, $result->rmse);
        self::assertLessThan(0.5, $result->rmse);
    }

    /**
     * Checks the color difference (chroma) when comparing a color image
     * with its grayscale version. The chroma difference must be significantly
     * above the threshold.
     */
    #[Test]
    public function colorToGrayscaleConversionProducesHighChromaDifference(): void
    {
        $colorImg = $this->createSolidImage(64, 64, 'red');

        // Create grayscale equivalent of the red image
        $grayImg = $this->createSolidImage(64, 64, 'red');
        $grayImg->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $grayImg->transformImageColorspace(Imagick::COLORSPACE_SRGB);

        $result = $this->analyzer->analyzeRmse($colorImg, $grayImg);

        // Chroma difference must be well above MAX_CHROMA_DIFFERENCE (0.05)
        // because the color image has high chroma and the gray version has zero
        self::assertGreaterThan(0.1, $result->chromaDifference);
        self::assertTrue($result->success);
    }

    /**
     * Ensures that in case of errors (e.g., invalid image data), a
     * "conservative" result (no changes) is returned instead of
     * aborting the process with an exception.
     */
    #[Test]
    public function analyzeReturnsConservativeResultOnFailure(): void
    {
        // A cleared Imagick object has no valid image data, causing internal methods to throw
        $invalidImg = new Imagick();
        $validImg   = $this->createSolidImage(64, 64, 'red');

        $result = $this->analyzer->analyze($invalidImg, $validImg);

        self::assertSame(0.0, $result->rmse);
        self::assertSame(0.0, $result->changedAreaRatio);
        self::assertSame(0.0, $result->largestBlobRatio);
        self::assertSame(0, $result->blobCount);
        self::assertFalse($result->hasCompactRetouch);
    }

    private function createSolidImage(int $width, int $height, string $color): Imagick
    {
        $img = new Imagick();
        $img->newImage($width, $height, new ImagickPixel($color));
        $img->setImageFormat('png');

        return $img;
    }
}
