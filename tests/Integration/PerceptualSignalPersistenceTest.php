<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use Imagick;
use MagicSunday\Renamer\Helper\NativeToolError;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashMath;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function clearstatcache;

/**
 * Exercises native signal calculation, group cleanup and persistent reload as
 * one lifecycle. Synthetic files keep the test independent of personal media;
 * a classifier spy proves warm complete signals avoid invoking the decoder.
 */
#[UsesClass(NativeToolError::class)]
#[UsesClass(NullProgressReporter::class)]
#[CoversClass(PerceptualHashCalculator::class)]
#[CoversClass(PerceptualSignalCache::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(PerceptualHashMath::class)]
#[UsesClass(PrivateCacheStorage::class)]
#[UsesClass(SimilarityResult::class)]
final class PerceptualSignalPersistenceTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Disposable source and private cache directory for one lifecycle.
     */
    private string $workspace;

    /**
     * Creates a fresh workspace so persisted signals cannot leak between tests.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        $this->workspace = $this->createTempWorkspace('signal-persistence-');
    }

    /**
     * Removes only this test's synthetic files and private cache after each case.
     *
     * @return void
     */
    #[Override]
    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);
    }

    /**
     * Computes all four signals from a real tiny MOV, verifies cleanup preserves
     * them, then reloads a fresh disk cache and calculator. The classifier must
     * never be called on the warm run, demonstrating native decoding is skipped.
     *
     * @return void
     */
    #[Test]
    public function completeSignalsSurviveCleanupAndFreshDiskReload(): void
    {
        $file       = $this->createVideo();
        $cache      = $this->createCache();
        $calculator = $this->createCalculator($cache);
        self::assertTrue($calculator->similarityScore($file, $file, 1.0, 1.0)->analysisAvailable);
        $signals = $cache->get($file);
        self::assertNotNull($signals);
        self::assertNotNull($signals['dhash']);
        self::assertNotNull($signals['whash']);
        self::assertNotNull($signals['hf']);
        self::assertNotNull($signals['hist']);

        $images = $this->loadedImages($calculator);
        self::assertNotEmpty($images);
        $calculator->clearCache();
        self::assertSame($signals, $cache->get($file));

        foreach ($images as $image) {
            self::assertSame(0, $image->getNumberImages(), 'Native buffers must be cleared even while a test retains the object.');
        }

        self::assertSame([], $this->loadedImages($calculator));
        $cache->flush();
        $reloaded = $this->createCache();
        // JSON preserves numeric values but may encode 0.0 as integer zero.
        self::assertEquals($signals, $reloaded->get($file));
        $classifier = self::createMock(MediaTypeClassifierInterface::class);
        $classifier->expects(self::never())->method('isVideo');
        $warm = new PerceptualHashCalculator(new ImagickImageLoader($classifier, new NullProgressReporter()), new PerceptualHashMath());
        $warm->setSignalCache($reloaded);

        $result = $warm->similarityScore($file, $file, 1.0, 1.0);
        self::assertTrue($result->analysisAvailable);
        self::assertSame(100, $result->score);
        self::assertSame([], $this->loadedImages($warm));
        $warm->clearCache();
        self::assertEquals($signals, $reloaded->get($file));
    }

    /**
     * Identical stills use the cheap dHash early exit. Cleanup must persist that
     * useful partial entry and free the decoded image instead of requiring the
     * expensive signals solely for persistence.
     *
     * @return void
     */
    #[Test]
    public function earlyExitPersistsDhashAndReleasesLoadedImages(): void
    {
        $path  = $this->workspace . '/still.jpg';
        $image = new Imagick();
        $image->newImage(64, 64, 'red', 'jpeg');
        $image->writeImage($path);
        $image->clear();

        $file       = new SplFileInfo($path);
        $cache      = $this->createCache();
        $calculator = $this->createCalculator($cache);
        self::assertSame(100, $calculator->similarityScore($file, $file)->score);
        $images = $this->loadedImages($calculator);
        $calculator->clearCache();
        $signals = $cache->get($file);
        self::assertNotNull($signals);
        self::assertNotNull($signals['dhash']);
        self::assertNull($signals['whash']);
        self::assertNull($signals['hf']);
        self::assertNull($signals['hist']);
        self::assertNotEmpty($images);

        foreach ($images as $loaded) {
            self::assertSame(0, $loaded->getNumberImages());
        }

        self::assertSame([], $this->loadedImages($calculator));
        $cache->flush();
        self::assertEquals($signals, $this->createCache()->get($file));
    }

    /**
     * A disk entry containing only dHash can later reach the expensive stage.
     * Its newly computed wHash/HF/histogram must survive the same cleanup path,
     * including when the image was loaded after the initial disk-cache hit.
     *
     * @return void
     */
    #[Test]
    public function enrichedDhashOnlyEntryRemainsCompleteAfterCleanup(): void
    {
        $file       = $this->createVideo();
        $cache      = $this->createCache();
        $calculator = $this->createCalculator($cache);
        $calculator->similarityScore($file, $file, 1.0, 1.0);

        $signals = $cache->get($file);
        self::assertNotNull($signals);
        $calculator->clearCache();
        $cache->set($file, ['dhash' => $signals['dhash'], 'whash' => null, 'hf' => null, 'hist' => null]);
        $cache->flush();

        $reloaded = $this->createCache();
        $fresh    = $this->createCalculator($reloaded);
        self::assertTrue($fresh->similarityScore($file, $file, 1.0, 1.0)->analysisAvailable);
        self::assertEquals($signals, $reloaded->get($file));
        $fresh->clearCache();
        self::assertEquals($signals, $reloaded->get($file));
        $reloaded->flush();
        self::assertEquals($signals, $this->createCache()->get($file));
    }

    /**
     * Changes the video bytes after cleanup and disk persistence. A new cache
     * rejects the old entry by size, and the existing calculator must also avoid
     * its previous native image and signals when the changed source cannot decode.
     *
     * @return void
     */
    #[Test]
    public function changedFileInvalidatesPersistentAndLocalSignals(): void
    {
        $file       = $this->createVideo();
        $cache      = $this->createCache();
        $calculator = $this->createCalculator($cache);
        self::assertTrue($calculator->similarityScore($file, $file, 1.0, 1.0)->analysisAvailable);
        $calculator->clearCache();
        $cache->flush();
        new Filesystem()->dumpFile($file->getPathname(), 'changed synthetic bytes, no longer a video');
        clearstatcache(true, $file->getPathname());
        self::assertNull($this->createCache()->get(new SplFileInfo($file->getPathname())));
        self::assertFalse($calculator->similarityScore($file, $file, 1.0, 1.0)->analysisAvailable);
        $calculator->clearCache();
    }

    /**
     * Generates a finite tiny MOV with native ffmpeg to exercise the video path
     * and expensive signals even when dHash matches exactly.
     *
     * @return SplFileInfo Actual generated synthetic video
     */
    private function createVideo(): SplFileInfo
    {
        $path    = $this->workspace . '/clip.mov';
        $process = new Process(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=blue:s=64x64:d=1', '-c:v', 'libx264', $path]);
        $process->setTimeout(10.0);
        $process->mustRun();

        return new SplFileInfo($path);
    }

    /**
     * Opens a new persistent cache under the test's owned private directory.
     *
     * @return PerceptualSignalCache Fresh cache instance, including a real disk reload
     */
    private function createCache(): PerceptualSignalCache
    {
        $storage = new PrivateCacheStorage(new Filesystem());

        return new PerceptualSignalCache($storage->directory($this->workspace) . '/signals.json', $storage);
    }

    /**
     * Wires real native loading and arithmetic to the selected persistent cache.
     *
     * @param PerceptualSignalCache $cache Cache whose lifecycle the test observes
     *
     * @return PerceptualHashCalculator Native calculator sharing that cache
     */
    private function createCalculator(PerceptualSignalCache $cache): PerceptualHashCalculator
    {
        $calculator = new PerceptualHashCalculator(new ImagickImageLoader(new MediaTypeClassifier(), new NullProgressReporter()), new PerceptualHashMath());
        $calculator->setSignalCache($cache);

        return $calculator;
    }

    /**
     * Retains native image references only to verify explicit buffer release.
     * Reflection is restricted to this resource assertion; signal preservation
     * and warm-cache reuse are tested through the public calculator/cache API.
     *
     * @param PerceptualHashCalculator $calculator Calculator whose native buffers are observed
     *
     * @return list<Imagick> Loaded native images, excluding disk-only cache entries
     */
    private function loadedImages(PerceptualHashCalculator $calculator): array
    {
        $entries = new ReflectionProperty($calculator, 'loadCache')->getValue($calculator);
        self::assertIsArray($entries);
        $images = [];

        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            $image = $entry['img'] ?? null;

            if ($image instanceof Imagick) {
                $images[] = $image;
            }
        }

        return $images;
    }
}
