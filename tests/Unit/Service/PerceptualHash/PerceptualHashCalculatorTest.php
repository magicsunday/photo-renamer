<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SplFileInfo;

use function copy;
use function escapeshellarg;
use function exec;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PerceptualHashCalculator::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(SimilarityResult::class)]
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
        return new PerceptualHashCalculator(new ImagickImageLoader(new MediaTypeClassifier()));
    }

    /**
     * Verifies that two identical images produce a 100% similarity score
     * and are classified as likely duplicates.
     */
    #[Test]
    public function similarityScoreReturnsValidResultForIdenticalImages(): void
    {
        $pathA = $this->createJpeg('a.jpg', 'red');
        $pathB = $this->createJpeg('b.jpg', 'red');

        $calculator = $this->createCalculator();
        $result     = $calculator->similarityScore(new SplFileInfo($pathA), new SplFileInfo($pathB));

        self::assertSame(100, $result->score);
        self::assertTrue($result->isDuplicateLikely());
        self::assertSame(0, $result->dhashDistance);
    }

    /**
     * Verifies that a comparison involving a non-existent file returns a
     * classification of "Different" and reports no duplicate likelihood.
     */
    #[Test]
    public function similarityScoreReturnsDifferentForNonExistentFile(): void
    {
        $pathA = $this->createJpeg('exists.jpg', 'white');

        $calculator = $this->createCalculator();
        $result     = $calculator->similarityScore(
            new SplFileInfo($pathA),
            new SplFileInfo('/does/not/exist.jpg'),
        );

        self::assertFalse($result->isDuplicateLikely());
        self::assertSame(SimilarityClassification::Different, $result->classification);
    }

    /**
     * Verifies that clearing the internal hash cache allows re-computation
     * of scores without affecting the result accuracy.
     */
    #[Test]
    public function clearCacheResetsState(): void
    {
        $pathA = $this->createJpeg('a.jpg', 'red');
        $pathB = $this->createJpeg('b.jpg', 'red');

        $calculator = $this->createCalculator();
        $result1    = $calculator->similarityScore(new SplFileInfo($pathA), new SplFileInfo($pathB));
        $calculator->clearCache();
        $result2 = $calculator->similarityScore(new SplFileInfo($pathA), new SplFileInfo($pathB));

        self::assertSame($result1->score, $result2->score, 'Same files should produce same score after cache clear');
    }

    /**
     * Verifies that perceptual hashing also works for video files by extracting
     * and comparing frames at specific timestamps.
     */
    #[Test]
    public function similarityScoreWorksForVideo(): void
    {
        $pathA = $this->tempDir . '/videoA.mov';
        $pathB = $this->tempDir . '/videoB.mov';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i color=blue:s=64x64:d=0.5 -c:v libx264 -f mov %s 2>/dev/null',
            escapeshellarg($pathA),
        ));
        copy($pathA, $pathB);

        $calculator = $this->createCalculator();
        $result     = $calculator->similarityScore(
            new SplFileInfo($pathA),
            new SplFileInfo($pathB),
            0.5,
            0.5,
        );

        self::assertSame(100, $result->score);
        self::assertTrue($result->isDuplicateLikely());
    }

    /**
     * Verifies that two files containing the same image but different EXIF metadata
     * (e.g. different Software tag) still result in a very high similarity score.
     * This confirms that perceptual hashing ignores non-visual metadata.
     */
    #[Test]
    public function sameImageWithDifferentExifProducesHighScore(): void
    {
        $pathA = $this->createJpeg('original.jpg', 'red');
        copy($pathA, $this->tempDir . '/copy.jpg');
        $pathB = $this->tempDir . '/copy.jpg';

        // Inject different EXIF metadata
        exec(sprintf('exiftool -overwrite_original -Software="Test 1.0" %s 2>/dev/null', escapeshellarg($pathA)));
        exec(sprintf('exiftool -overwrite_original -Software="Different 2.0" %s 2>/dev/null', escapeshellarg($pathB)));

        $calculator = $this->createCalculator();
        $result     = $calculator->similarityScore(new SplFileInfo($pathA), new SplFileInfo($pathB));

        self::assertLessThanOrEqual(5, $result->dhashDistance, 'Same image with different EXIF should have very close dHash');
        self::assertTrue($result->isDuplicateLikely());
    }

    /**
     * Verifies bit packing for short and fixed-width bit strings.
     */
    #[Test]
    public function bitsToHexPadsToNibbleAndTargetWidth(): void
    {
        $calculator = $this->createCalculator();
        $method     = new ReflectionMethod($calculator, 'bitsToHex');

        self::assertSame('5', $method->invoke($calculator, '101'));
        self::assertSame('05', $method->invoke($calculator, '101', 8));
        self::assertSame('0005', $method->invoke($calculator, '101', 16));
    }

    /**
     * Verifies strict hex decoding behavior through the public distance contract.
     */
    #[Test]
    public function hammingDistanceRejectsInvalidHexAndCountsUnequalLengths(): void
    {
        $calculator = $this->createCalculator();
        $method     = new ReflectionMethod($calculator, 'hammingDistance');

        self::assertSame(64, $method->invoke($calculator, 'abc', '00'));
        self::assertSame(64, $method->invoke($calculator, 'zz', '00'));
        self::assertSame(16, $method->invoke($calculator, 'ff', '00ff'));
        self::assertSame(4, $method->invoke($calculator, '0f', '00'));
    }

    /**
     * Verifies score weighting at image/video boundaries, including the video
     * color-noise suppression window for near-identical durations.
     */
    #[Test]
    public function weightedScoreHandlesImageAndVideoDurationBoundaries(): void
    {
        $calculator = $this->createCalculator();
        $method     = new ReflectionMethod($calculator, 'computeWeightedScore');

        self::assertSame(74, $method->invoke($calculator, 8, 16, 0.03, 0.50, null, false));
        self::assertSame(90, $method->invoke($calculator, 8, 8, 0.03, 1.00, 1.0, true));
        self::assertSame(51, $method->invoke($calculator, 8, 8, 0.03, 1.00, 31.0, true));
    }

    /**
     * Verifies the population count helper for zero and multi-bit values.
     */
    #[Test]
    public function bitcountCountsOnlySetBits(): void
    {
        $calculator = $this->createCalculator();
        $method     = new ReflectionMethod($calculator, 'bitcount');

        self::assertSame(0, $method->invoke($calculator, 0));
        self::assertSame(4, $method->invoke($calculator, 0b10101010));
        self::assertSame(8, $method->invoke($calculator, 0xFF));
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
}
