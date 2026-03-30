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

    #[Test]
    public function completelyDifferentImagesProduceHighChangedArea(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'white');
        $imgB = $this->createSolidImage(64, 64, 'black');

        $result = $this->analyzer->analyze($imgA, $imgB);

        self::assertGreaterThan(0.5, $result->rmse);
        self::assertGreaterThan(0.5, $result->changedAreaRatio);
        self::assertGreaterThan(0, $result->blobCount);
    }

    #[Test]
    public function singlePixelNoiseIsRemovedByMorphology(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'white');
        $imgB = $this->createSolidImage(64, 64, 'white');

        // Change exactly one pixel to black (max contrast against white)
        $pixel = new ImagickPixel('black');
        $draw  = new ImagickDraw();
        $draw->setFillColor($pixel);
        $draw->point(32, 32);

        $imgB->drawImage($draw);

        $result = $this->analyzer->analyze($imgA, $imgB);

        // Morphological opening with a 3x3 cross kernel removes isolated pixels
        self::assertSame(0, $result->blobCount);
        self::assertSame(0.0, $result->changedAreaRatio);
        self::assertFalse($result->hasCompactRetouch);
    }

    #[Test]
    public function compactBlobDetectedAsRetouch(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'white');
        $imgB = $this->createSolidImage(64, 64, 'white');

        // Draw a 10x10 black square into one image to simulate a local retouch
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));
        $draw->rectangle(20, 20, 30, 30);

        $imgB->drawImage($draw);

        $result = $this->analyzer->analyze($imgA, $imgB);

        self::assertTrue($result->hasCompactRetouch);
        self::assertGreaterThan(0, $result->blobCount);
        self::assertGreaterThan(0.0, $result->largestBlobRatio);
    }

    #[Test]
    public function scatteredNoiseNotClassifiedAsRetouch(): void
    {
        $imgA = $this->createSolidImage(64, 64, 'gray');
        $imgB = $this->createSolidImage(64, 64, 'gray');

        // Place isolated single pixels on a regular grid with stride 3.
        // No two pixels share a 4-connected neighbor, so morphological
        // opening (erode) removes them all.
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));

        for ($y = 1; $y < 64; $y += 3) {
            for ($x = 1; $x < 64; $x += 3) {
                $draw->point($x, $y);
            }
        }

        $imgB->drawImage($draw);

        $result = $this->analyzer->analyze($imgA, $imgB);

        self::assertFalse($result->hasCompactRetouch);
    }

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
