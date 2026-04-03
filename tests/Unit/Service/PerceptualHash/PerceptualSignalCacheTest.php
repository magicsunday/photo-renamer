<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

use function clearstatcache;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the PerceptualSignalCache service, which provides a persistent
 * disk cache for perceptual hash signals keyed by file pathname.
 *
 * Key guarantees:
 * - Cache hit returns the stored signals when mtime and size match
 * - Cache miss returns null for unknown files
 * - Stale entries (changed mtime or size) are evicted and return null
 * - Flush persists entries to disk only when dirty
 * - Flush is a no-op when no entries have been added or invalidated
 * - Corrupt JSON on disk does not crash construction
 * - Missing cache file on construction starts with empty entries
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PerceptualSignalCache::class)]
final class PerceptualSignalCacheTest extends TestCase
{
    private string $workspace;

    private string $cacheFile;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('signal_cache_', true);

        if (!mkdir($this->workspace, 0o755, true) && !is_dir($this->workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        $this->cacheFile = $this->workspace . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'signal-cache.json';
    }

    protected function tearDown(): void
    {
        // Clean up cache file and directories
        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }

        $cacheDir = $this->workspace . DIRECTORY_SEPARATOR . 'cache';

        if (is_dir($cacheDir)) {
            rmdir($cacheDir);
        }

        // Clean up any test files
        $testFile = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';

        if (is_file($testFile)) {
            unlink($testFile);
        }

        if (is_dir($this->workspace)) {
            rmdir($this->workspace);
        }
    }

    /**
     * Verifies that set() followed by get() returns the stored signals
     * when the file's mtime and size have not changed.
     */
    #[Test]
    public function getReturnsCachedSignalsForKnownFile(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'fake image data');

        $file  = new SplFileInfo($filePath);
        $cache = $this->createCache();

        $signals = [
            'dhash' => 'abcdef0123456789',
            'whash' => '9876543210fedcba',
            'hf'    => 0.42,
            'hist'  => [0.1, 0.2, 0.3, 0.4],
        ];

        $cache->set($file, $signals);

        $result = $cache->get($file);

        self::assertNotNull($result);
        self::assertSame('abcdef0123456789', $result['dhash']);
        self::assertSame('9876543210fedcba', $result['whash']);
        self::assertSame(0.42, $result['hf']);
        self::assertSame([0.1, 0.2, 0.3, 0.4], $result['hist']);
    }

    /**
     * Verifies that get() returns null for a file that has never been cached.
     */
    #[Test]
    public function getReturnsNullForUnknownFile(): void
    {
        $cache = $this->createCache();
        $file  = new SplFileInfo('/nonexistent/photo.jpg');

        self::assertNull($cache->get($file));
    }

    /**
     * Verifies that a cached entry is evicted and get() returns null
     * when the file's mtime changes after caching.
     */
    #[Test]
    public function getReturnsNullAndEvictsWhenMtimeChanged(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'image data here!');

        $file  = new SplFileInfo($filePath);
        $cache = $this->createCache();

        $cache->set($file, [
            'dhash' => 'aaaa',
            'whash' => 'bbbb',
            'hf'    => 1.0,
            'hist'  => [0.5],
        ]);

        // Change mtime to the future (keep same size by writing same length)
        touch($filePath, time() + 100);
        clearstatcache(true, $filePath);

        $freshFile = new SplFileInfo($filePath);

        self::assertNull($cache->get($freshFile));
    }

    /**
     * Verifies that a cached entry is evicted and get() returns null
     * when the file's size changes after caching.
     */
    #[Test]
    public function getReturnsNullAndEvictsWhenSizeChanged(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'original content');

        $file  = new SplFileInfo($filePath);
        $cache = $this->createCache();

        $cache->set($file, [
            'dhash' => 'cccc',
            'whash' => 'dddd',
            'hf'    => 0.0,
            'hist'  => null,
        ]);

        // Change file content (different size)
        file_put_contents($filePath, 'modified content with different length!!');
        clearstatcache(true, $filePath);

        $freshFile = new SplFileInfo($filePath);

        self::assertNull($cache->get($freshFile));
    }

    /**
     * Verifies that flush() persists entries to disk as a JSON file and
     * a new instance loads them back, confirming cross-process persistence.
     */
    #[Test]
    public function flushWritesToDiskWhenDirty(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'persistent data');

        $file   = new SplFileInfo($filePath);
        $cache1 = $this->createCache();

        $signals = [
            'dhash' => 'eeee',
            'whash' => null,
            'hf'    => null,
            'hist'  => [0.15, 0.85],
        ];

        $cache1->set($file, $signals);
        $cache1->flush();

        self::assertFileExists($this->cacheFile);

        // New instance should load the flushed entries
        $cache2 = $this->createCache();
        $result = $cache2->get($file);

        self::assertNotNull($result);
        self::assertSame('eeee', $result['dhash']);
        self::assertNull($result['whash']);
        self::assertNull($result['hf']);
        self::assertSame([0.15, 0.85], $result['hist']);
    }

    /**
     * Verifies that flush() does not write to disk when the cache was
     * loaded from disk and no entries were added or invalidated.
     */
    #[Test]
    public function flushIsNoOpWhenClean(): void
    {
        $cache = $this->createCache();
        $cache->flush();

        self::assertFileDoesNotExist($this->cacheFile);
    }

    /**
     * Verifies that construction with a corrupt (non-JSON) cache file
     * does not throw and starts with empty entries.
     */
    #[Test]
    public function loadIgnoresCorruptJson(): void
    {
        $cacheDir = $this->workspace . DIRECTORY_SEPARATOR . 'cache';

        if (!mkdir($cacheDir, 0o755, true) && !is_dir($cacheDir)) {
            self::fail('Unable to create cache directory.');
        }

        file_put_contents($this->cacheFile, '{{{not valid json!!!');

        $cache = $this->createCache();

        self::assertNull($cache->get(new SplFileInfo('/any/file.jpg')));
    }

    /**
     * Verifies that construction with a non-existent cache file does not
     * throw and get() returns null for any file.
     */
    #[Test]
    public function loadStartsEmptyWhenCacheFileMissing(): void
    {
        $cache = $this->createCache();

        // Should not throw, simply start with empty entries
        self::assertNull($cache->get(new SplFileInfo('/any/file.jpg')));
    }

    /**
     * Creates the cache under test with an explicit Symfony Filesystem dependency.
     *
     * The production cache no longer instantiates its own filesystem collaborator.
     * Tests use this helper so every construction path mirrors the new DI shape.
     *
     * @return PerceptualSignalCache Cache instance backed by the temporary workspace
     */
    private function createCache(): PerceptualSignalCache
    {
        return new PerceptualSignalCache($this->cacheFile, new Filesystem());
    }
}
