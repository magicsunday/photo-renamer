<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Metadata;

use DateTimeImmutable;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\MetadataCacheEntry;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

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
#[UsesClass(MetadataCacheEntry::class)]
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
        $cache = $this->createCache();
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
        $cache = $this->createCache();

        $cache->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-05-05T12:34:56+02:00'),
            'uuid-1234',
        ));

        $entry = $cache->get($file);

        self::assertNotNull($entry);
        self::assertSame('2024-05-05T12:34:56.000000+02:00', $entry->getCaptureDateTime()?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('uuid-1234', $entry->getContentId());
        self::assertFalse($entry->isFallback());
        self::assertFalse($entry->isAmbiguousTimezone());
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
        $cache = $this->createCache();

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
        $cache = $this->createCache();

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
        $cache1 = $this->createCache();

        $cache1->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            'content-id',
            true,
            true,
        ));
        $cache1->flush();

        self::assertFileExists($this->cacheFile);

        // New instance should load the flushed entries
        $cache2 = $this->createCache();
        $entry  = $cache2->get($file);

        self::assertNotNull($entry);
        self::assertSame('2024-01-01T00:00:00.000000+00:00', $entry->getCaptureDateTime()?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('content-id', $entry->getContentId());
        self::assertTrue($entry->isFallback());
        self::assertTrue($entry->isAmbiguousTimezone());
    }

    /**
     * Verifies that flush() does not write to disk when no entries have been
     * modified (not dirty).
     */
    #[Test]
    public function flushDoesNotWriteWhenNotDirty(): void
    {
        $cache = $this->createCache();
        $cache->flush();

        self::assertFileDoesNotExist($this->cacheFile);
    }

    /**
     * Verifies that construction with a non-existent cache file does not throw.
     */
    #[Test]
    public function constructionWithMissingCacheFileIsGraceful(): void
    {
        $cache = $this->createCache();

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
        $cache = $this->createCache();

        $cache->set($file, null);

        $entry = $cache->get($file);

        self::assertNotNull($entry);
        self::assertNull($entry->getCaptureDateTime());
        self::assertNull($entry->getContentId());
        self::assertFalse($entry->isFallback());
        self::assertFalse($entry->isAmbiguousTimezone());
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
        $cache = $this->createCache();

        $cache->set($file, new TemporalMetadata(
            new DateTimeImmutable('2024-06-15T08:00:00+00:00'),
            null,
        ));
        $cache->flush();

        $cacheDir = $this->workspace . DIRECTORY_SEPARATOR . 'cache';

        self::assertDirectoryExists($cacheDir);
        self::assertFileExists($this->cacheFile);
    }

    /**
     * Creates the cache under test with a concrete Symfony Filesystem instance.
     *
     * Production code now injects the filesystem dependency explicitly instead
     * of relying on constructor-default instantiation. Tests use this helper to
     * keep the setup compact while preserving the same DI shape.
     *
     * @return MetadataCache Cache instance backed by the temporary workspace
     */
    private function createCache(): MetadataCache
    {
        return new MetadataCache($this->cacheFile, new Filesystem());
    }
}
