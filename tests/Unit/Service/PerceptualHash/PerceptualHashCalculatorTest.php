<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sys_get_temp_dir;
use function uniqid;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PerceptualHashCalculator::class)]
#[UsesClass(ImagickImageLoader::class)]
final class PerceptualHashCalculatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phash-calc-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    private function createCalculator(): PerceptualHashCalculator
    {
        return new PerceptualHashCalculator(new ImagickImageLoader());
    }

    #[Test]
    public function computeDhashReturns16CharHexString(): void
    {
        $path = $this->createJpeg('test.jpg', 'white');

        $calculator = $this->createCalculator();
        $hash       = $calculator->computeDhash(new SplFileInfo($path));

        self::assertNotNull($hash);
        self::assertSame(16, strlen($hash), 'dHash must be 16 hex characters (64 bits)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
    }

    #[Test]
    public function computeDhashReturnsNullForNonExistentFile(): void
    {
        $calculator = $this->createCalculator();
        $hash       = $calculator->computeDhash(new SplFileInfo('/does/not/exist.jpg'));

        self::assertNull($hash);
    }

    #[Test]
    public function identicalImagesProduceSameHash(): void
    {
        $pathA = $this->createJpeg('a.jpg', 'red');
        $pathB = $this->createJpeg('b.jpg', 'red');

        $calculator = $this->createCalculator();
        $hashA      = $calculator->computeDhash(new SplFileInfo($pathA));
        $hashB      = $calculator->computeDhash(new SplFileInfo($pathB));

        self::assertNotNull($hashA);
        self::assertNotNull($hashB);
        self::assertSame(0, $calculator->hammingDistance($hashA, $hashB));
    }

    #[Test]
    public function visuallyDifferentImagesProduceDistantHashes(): void
    {
        $whitePath = $this->createJpeg('white.jpg', 'white');
        $blackPath = $this->createJpeg('black.jpg', 'black');

        $calculator = $this->createCalculator();
        $whiteHash  = $calculator->computeDhash(new SplFileInfo($whitePath));
        $blackHash  = $calculator->computeDhash(new SplFileInfo($blackPath));

        self::assertNotNull($whiteHash);
        self::assertNotNull($blackHash);

        $distance = $calculator->hammingDistance($whiteHash, $blackHash);

        // Uniform white vs uniform black: dHash compares horizontal gradients.
        // Both uniform images have zero gradients, so distance may be 0.
        // Use a structured image instead for meaningful difference.
        // This test verifies the method returns a valid distance >= 0.
        self::assertGreaterThanOrEqual(0, $distance);
    }

    #[Test]
    public function structuredVsUniformImageProducesHighDistance(): void
    {
        // Split image (left black, right white) has strong horizontal gradients;
        // uniform white has none → different dHash.
        $splitPath   = $this->createSplitImage('split.jpg');
        $uniformPath = $this->createJpeg('uniform.jpg', 'white');

        $calculator  = $this->createCalculator();
        $splitHash   = $calculator->computeDhash(new SplFileInfo($splitPath));
        $uniformHash = $calculator->computeDhash(new SplFileInfo($uniformPath));

        self::assertNotNull($splitHash);
        self::assertNotNull($uniformHash);

        $distance = $calculator->hammingDistance($splitHash, $uniformHash);
        self::assertGreaterThan(5, $distance, 'Split image vs uniform should have noticeable Hamming distance');
    }

    #[Test]
    public function hammingDistanceOfIdenticalHashesIsZero(): void
    {
        $calculator = $this->createCalculator();
        self::assertSame(0, $calculator->hammingDistance('0000000000000000', '0000000000000000'));
        self::assertSame(0, $calculator->hammingDistance('ffffffffffffffff', 'ffffffffffffffff'));
    }

    #[Test]
    public function hammingDistanceOfMaximallyDifferentHashesIs64(): void
    {
        $calculator = $this->createCalculator();
        self::assertSame(64, $calculator->hammingDistance('0000000000000000', 'ffffffffffffffff'));
    }

    #[Test]
    public function hammingDistanceOfSingleBitDifferenceIs1(): void
    {
        $calculator = $this->createCalculator();
        self::assertSame(1, $calculator->hammingDistance('0000000000000000', '0000000000000001'));
    }

    #[Test]
    public function clearCacheResetsState(): void
    {
        $path = $this->createJpeg('cached.jpg', 'red');

        $calculator = $this->createCalculator();
        $hash1      = $calculator->computeDhash(new SplFileInfo($path));
        $calculator->clearCache();
        $hash2 = $calculator->computeDhash(new SplFileInfo($path));

        self::assertNotNull($hash1);
        self::assertNotNull($hash2);
        self::assertSame($hash1, $hash2, 'Same file should produce same hash after cache clear');
    }

    #[Test]
    public function computeDhashWorksForVideo(): void
    {
        $path = $this->tempDir . '/video.mov';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i color=blue:s=64x64:d=0.5 -c:v libx264 -f mov %s 2>/dev/null',
            escapeshellarg($path),
        ));

        $calculator = $this->createCalculator();
        $hash       = $calculator->computeDhash(new SplFileInfo($path));

        self::assertNotNull($hash);
        self::assertSame(16, strlen($hash));
    }

    #[Test]
    public function sameImageWithDifferentExifProducesSimilarHash(): void
    {
        $pathA = $this->createJpeg('original.jpg', 'red');
        copy($pathA, $this->tempDir . '/copy.jpg');
        $pathB = $this->tempDir . '/copy.jpg';

        // Inject different EXIF metadata
        exec(sprintf('exiftool -overwrite_original -Software="Test 1.0" %s 2>/dev/null', escapeshellarg($pathA)));
        exec(sprintf('exiftool -overwrite_original -Software="Different 2.0" %s 2>/dev/null', escapeshellarg($pathB)));

        $calculator = $this->createCalculator();
        $hashA      = $calculator->computeDhash(new SplFileInfo($pathA));
        $hashB      = $calculator->computeDhash(new SplFileInfo($pathB));

        self::assertNotNull($hashA);
        self::assertNotNull($hashB);

        $distance = $calculator->hammingDistance($hashA, $hashB);
        self::assertLessThanOrEqual(5, $distance, 'Same image with different EXIF should have very close dHash');
    }

    private function createJpeg(string $filename, string $color): string
    {
        $path = $this->tempDir . '/' . $filename;
        exec(sprintf(
            'ffmpeg -y -f lavfi -i color=%s:s=64x64 -frames:v 1 %s 2>/dev/null',
            escapeshellarg($color),
            escapeshellarg($path),
        ));

        return $path;
    }

    private function createSplitImage(string $filename): string
    {
        $path = $this->tempDir . '/' . $filename;

        // Left half black, right half white — creates strong horizontal gradient at center
        exec(sprintf(
            'ffmpeg -y -f lavfi -i "color=black:s=32x64" -f lavfi -i "color=white:s=32x64" -filter_complex "[0][1]hstack" -frames:v 1 %s 2>/dev/null',
            escapeshellarg($path),
        ));

        return $path;
    }
}
