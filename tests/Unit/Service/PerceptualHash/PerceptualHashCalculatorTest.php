<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashMath;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

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
#[UsesClass(NullProgressReporter::class)]
#[CoversClass(PerceptualHashCalculator::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(PerceptualHashMath::class)]
#[UsesClass(PerceptualSignalCache::class)]
#[UsesClass(PrivateCacheStorage::class)]
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
        return new PerceptualHashCalculator(new ImagickImageLoader(new MediaTypeClassifier(), new NullProgressReporter()), new PerceptualHashMath());
    }

    /**
     * Starting a new cache scope after prior sources were removed must release
     * native/transient state without reading or writing those stale paths.
     * Native equal-image comparison leaves dHash-only early-exit entries; the
     * cache switch must discard them rather than perform late cache completion.
     *
     * @return void
     */
    #[Test]
    public function cacheSwitchDoesNotTouchRemovedPreviousSources(): void
    {
        $filesystem        = new Filesystem();
        $storage           = new PrivateCacheStorage($filesystem);
        $pathA             = $this->createJpeg('previous-a.jpg', 'red');
        $pathB             = $this->createJpeg('previous-b.jpg', 'red');
        $previousCachePath = $this->tempDir . '/previous-cache.json';
        $previousCache     = new PerceptualSignalCache($previousCachePath, $storage);
        $nextCache         = new PerceptualSignalCache($this->tempDir . '/next-cache.json', $storage);
        $calculator        = $this->createCalculator();
        $calculator->setSignalCache($previousCache);
        self::assertSame(0, $calculator->similarityScore(new SplFileInfo($pathA), new SplFileInfo($pathB))->dhashDistance);
        $filesystem->remove([$pathA, $pathB]);
        $calculator->setSignalCache($nextCache);
        $previousCache->flush();
        self::assertFileDoesNotExist($previousCachePath);
        $nextPath = $this->createJpeg('next.jpg', 'blue');
        $nextFile = new SplFileInfo($nextPath);
        self::assertSame(0, $calculator->similarityScore($nextFile, $nextFile)->dhashDistance);
        $calculator->clearCache();
    }

    /**
     * A shared calculator must use the newly attached run cache rather than
     * transient hashes from its prior run. Synthetic full-signal cache rows
     * distinguish identical versus different hashes without native decoding;
     * switching back must restore the first run's evidence without changing rows.
     *
     * @return void
     */
    #[Test]
    public function switchingRunCachesDiscardsPreviousTransientSignals(): void
    {
        $filesystem = new Filesystem();
        $storage    = new PrivateCacheStorage($filesystem);
        $firstPath  = $this->tempDir . '/first.jpg';
        $secondPath = $this->tempDir . '/second.jpg';
        $filesystem->dumpFile($firstPath, 'synthetic first bytes');
        $filesystem->dumpFile($secondPath, 'synthetic second bytes');

        $first     = new SplFileInfo($firstPath);
        $second    = new SplFileInfo($secondPath);
        $cacheA    = new PerceptualSignalCache($this->tempDir . '/a.json', $storage);
        $cacheB    = new PerceptualSignalCache($this->tempDir . '/b.json', $storage);
        $equal     = ['dhash' => '0000000000000000', 'whash' => '0000000000000000', 'hf' => 0.0, 'hist' => [1.0]];
        $different = ['dhash' => 'ffffffffffffffff', 'whash' => '0000000000000000', 'hf' => 0.0, 'hist' => [1.0]];
        $cacheA->set($first, $equal);
        $cacheA->set($second, $equal);

        $cacheB->set($first, $equal);
        $cacheB->set($second, $different);

        $calculator = $this->createCalculator();
        $calculator->setSignalCache($cacheA);
        self::assertSame(0, $calculator->similarityScore($first, $second)->dhashDistance);
        $calculator->setSignalCache($cacheB);
        self::assertSame(64, $calculator->similarityScore($first, $second)->dhashDistance);
        $calculator->setSignalCache($cacheA);
        self::assertSame(0, $calculator->similarityScore($first, $second)->dhashDistance);
        self::assertSame($different, $cacheB->get($second));
        self::assertSame('synthetic first bytes', $filesystem->readFile($firstPath));
        self::assertSame('synthetic second bytes', $filesystem->readFile($secondPath));
        $calculator->clearCache();
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
        self::assertTrue($result->analysisAvailable);
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
        self::assertFalse($result->analysisAvailable);
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
