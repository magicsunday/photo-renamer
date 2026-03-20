<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use DateTimeImmutable;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\MetadataCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the MetadataCache service, which provides a persistent disk cache
 * for metadata extraction results keyed by file pathname.
 *
 * Key guarantees:
 * - Cache miss returns null for unknown files
 * - Cache hit returns the stored entry when mtime and size match
 * - Stale entries (changed mtime or size) are invalidated and return null
 * - Flush persists entries to disk only when dirty
 * - A fresh instance loads previously flushed entries from disk
 * - Missing cache files on construction are handled gracefully
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(MetadataCache::class)]
#[UsesClass(TemporalMetadata::class)]
final class MetadataCacheTest extends TestCase
{
    private string $workspace;

    private string $cacheFile;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('meta_cache_', true);

        if (!mkdir($this->workspace, 0o755, true) && !is_dir($this->workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        $this->cacheFile = $this->workspace . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'metadata-cache.php';
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
     * Verifies that get() returns null for a file that has never been cached.
     */
    #[Test]
    public function getReturnsNullForUnknownFile(): void
    {
        $cache = new MetadataCache($this->cacheFile);
        $file  = new SplFileInfo('/nonexistent/photo.jpg');

        self::assertNull($cache->get($file));
    }

    /**
     * Verifies that set() followed by get() returns the stored entry when
     * the file's mtime and size have not changed.
     */
    #[Test]
    public function getReturnsCachedEntryOnHit(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'fake image data');

        $file  = new SplFileInfo($filePath);
        $cache = new MetadataCache($this->cacheFile);

        $cache->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-05-05T12:34:56+02:00'),
            'uuid-1234',
        ));

        $entry = $cache->get($file);

        self::assertNotNull($entry);
        self::assertSame('2024-05-05T12:34:56.000000+02:00', $entry['captureDateTime']);
        self::assertSame('uuid-1234', $entry['contentId']);
        self::assertFalse($entry['isFallback']);
        self::assertFalse($entry['isAmbiguousTimezone']);
    }

    /**
     * Verifies that a cached entry is invalidated when the file's size changes.
     */
    #[Test]
    public function getReturnsNullWhenFileSizeChanges(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'original content');

        $file  = new SplFileInfo($filePath);
        $cache = new MetadataCache($this->cacheFile);

        $cache->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-05-05T12:34:56+02:00'),
            null,
        ));

        // Change file content (different size)
        file_put_contents($filePath, 'modified content with different length!!');
        clearstatcache(true, $filePath);

        $freshFile = new SplFileInfo($filePath);

        self::assertNull($cache->get($freshFile));
    }

    /**
     * Verifies that a cached entry is invalidated when the file's mtime changes.
     */
    #[Test]
    public function getReturnsNullWhenFileMtimeChanges(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'image data here!');

        $file  = new SplFileInfo($filePath);
        $cache = new MetadataCache($this->cacheFile);

        $cache->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-05-05T12:34:56+02:00'),
            null,
        ));

        // Change mtime to the future (keep same size by writing same length)
        touch($filePath, time() + 100);
        clearstatcache(true, $filePath);

        $freshFile = new SplFileInfo($filePath);

        self::assertNull($cache->get($freshFile));
    }

    /**
     * Verifies that flush() persists entries to disk and a new instance
     * loads them back, confirming cross-process persistence.
     */
    #[Test]
    public function flushPersistsAndNewInstanceLoadsEntries(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'persistent data');

        $file   = new SplFileInfo($filePath);
        $cache1 = new MetadataCache($this->cacheFile);

        $cache1->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            'content-id',
            true,
            true,
        ));
        $cache1->flush();

        self::assertFileExists($this->cacheFile);

        // New instance should load the flushed entries
        $cache2 = new MetadataCache($this->cacheFile);
        $entry  = $cache2->get($file);

        self::assertNotNull($entry);
        self::assertSame('2024-01-01T00:00:00.000000+00:00', $entry['captureDateTime']);
        self::assertSame('content-id', $entry['contentId']);
        self::assertTrue($entry['isFallback']);
        self::assertTrue($entry['isAmbiguousTimezone']);
    }

    /**
     * Verifies that flush() does not write to disk when no entries have been
     * modified (not dirty).
     */
    #[Test]
    public function flushDoesNotWriteWhenNotDirty(): void
    {
        $cache = new MetadataCache($this->cacheFile);
        $cache->flush();

        self::assertFileDoesNotExist($this->cacheFile);
    }

    /**
     * Verifies that construction with a non-existent cache file does not throw.
     */
    #[Test]
    public function constructionWithMissingCacheFileIsGraceful(): void
    {
        $cache = new MetadataCache($this->cacheFile);

        // Should not throw, simply start with empty entries
        self::assertNull($cache->get(new SplFileInfo('/any/file.jpg')));
    }

    /**
     * Verifies that null captureDateTime and null contentId are stored and
     * retrieved correctly.
     */
    #[Test]
    public function getReturnsCachedEntryWithNullValues(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'no metadata');

        $file  = new SplFileInfo($filePath);
        $cache = new MetadataCache($this->cacheFile);

        $cache->set($file, null);

        $entry = $cache->get($file);

        self::assertNotNull($entry);
        self::assertNull($entry['captureDateTime']);
        self::assertNull($entry['contentId']);
        self::assertFalse($entry['isFallback']);
        self::assertFalse($entry['isAmbiguousTimezone']);
    }

    /**
     * Verifies that flush() creates the cache directory when it does not exist.
     */
    #[Test]
    public function flushCreatesDirectoryWhenMissing(): void
    {
        $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($filePath, 'data');

        $file  = new SplFileInfo($filePath);
        $cache = new MetadataCache($this->cacheFile);

        $cache->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-06-15T08:00:00+00:00'),
            null,
        ));
        $cache->flush();

        $cacheDir = $this->workspace . DIRECTORY_SEPARATOR . 'cache';

        self::assertDirectoryExists($cacheDir);
        self::assertFileExists($this->cacheFile);
    }
}
