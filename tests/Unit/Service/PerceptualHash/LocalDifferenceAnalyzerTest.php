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
 * Protects measured grayscale/chroma values and explicit failure availability
 * through the retained analysis entry point, using real native images.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LocalDifferenceAnalyzer::class)]
#[UsesClass(LocalDiffResult::class)]
final class LocalDifferenceAnalyzerTest extends TestCase
{
    private LocalDifferenceAnalyzer $analyzer;

    /**
     * Creates a fresh stateless analyzer for independent native-image cases.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->analyzer = new LocalDifferenceAnalyzer();
    }

    /**
     * Ensures that identical images produce no differences.
     * Successful evidence must contain exactly zero grayscale and chroma difference.
     *
     * @return void
     */
    #[Test]
    public function identicalImagesProduceZeroChanges(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'red');
        $imgB = $this->createSolidImage(64, 64, 'red');

        $result = $this->analyzer->analyze($imgA, $imgB);

        self::assertSame(0.0, $result->rmse);
        self::assertSame(0.0, $result->chromaDifference);
        self::assertTrue($result->success);
    }

    /**
     * Verifies that completely different images (white vs. black) produce a
     * high Root Mean Square Error (RMSE) value.
     *
     * @return void
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
     *
     * @return void
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
     * A small black patch on a white image produces a measurable aggregate
     * RMSE between 0.05 and 0.5. This verifies the measurement without claiming
     * spatial retouch detection or deciding whether classification permits a merge.
     *
     * @return void
     */
    #[Test]
    public function localRetouchProducesModerateRmse(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'white');
        $imgB = $this->createSolidImage(64, 64, 'white');

        // Draw a small black square to simulate a local retouch
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));
        $draw->rectangle(20, 20, 30, 30);

        $imgB->drawImage($draw);

        $result = $this->analyzer->analyze($imgA, $imgB);

        // The local black patch produces a noticeable aggregate RMSE.
        self::assertGreaterThan(0.05, $result->rmse);
        self::assertLessThan(0.5, $result->rmse);
    }

    /**
     * Checks the color difference (chroma) when comparing a color image
     * with its grayscale version. The chroma difference must be significantly
     * above the threshold.
     *
     * @return void
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

        // Chroma difference must be well above the chroma merge-veto threshold
        // because the color image has high chroma and the gray version has zero
        self::assertGreaterThan(0.1, $result->chromaDifference);
        self::assertTrue($result->success);
    }

    /**
     * A cleared native image must report unavailable evidence even though its
     * numeric fields are zero, preventing decode failure from appearing as a match.
     *
     * @return void
     */
    #[Test]
    public function analyzeReturnsConservativeResultOnFailure(): void
    {
        // A cleared Imagick object has no valid image data, causing internal methods to throw
        $invalidImg = new Imagick();
        $validImg   = $this->createSolidImage(64, 64, 'red');

        $result = $this->analyzer->analyze($invalidImg, $validImg);

        self::assertSame(0.0, $result->rmse);
        self::assertSame(0.0, $result->chromaDifference);
        self::assertFalse($result->success);
    }

    /**
     * Creates deterministic solid native pixels without codec or metadata noise.
     *
     * @param int    $width  Working image width.
     * @param int    $height Working image height.
     * @param string $color  Native fill color for the measurement case.
     *
     * @return Imagick The uncompressed PNG image supplied to the analyzer.
     */
    private function createSolidImage(int $width, int $height, string $color): Imagick
    {
        $img = new Imagick();
        $img->newImage($width, $height, new ImagickPixel($color));
        $img->setImageFormat('png');

        return $img;
    }
}
