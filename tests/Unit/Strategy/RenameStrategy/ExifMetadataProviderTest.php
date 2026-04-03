<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use DateTimeImmutable;
use DateTimeInterface;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FilenameDateParser;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\MetadataCacheEntry;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
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
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies ExifMetadataProvider, the caching facade over MetadataExtractor
 * that provides direct access to capture timestamps and content identifiers.
 *
 * Key guarantees:
 * - Capture timestamps are returned as-is from TemporalMetadata (with microsecond precision)
 * - Content identifiers are normalised (lowercased, trimmed) for case-insensitive pairing
 * - Missing metadata returns null instead of throwing
 * - Extraction errors preserve the original ExifMetadataReadException type
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExifMetadataProvider::class)]
#[UsesClass(FilenameDateParser::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(MetadataCacheEntry::class)]
final class ExifMetadataProviderTest extends TestCase
{
    /**
     * Verifies that the capture timestamp is correctly extracted from the EXIF
     * metadata. This ensures that the primary sorting and naming criterion
     * (the capture time) is accurately preserved.
     */
    #[Test]
    public function itReturnsCaptureDateTime(): void
    {
        $path              = '/tmp/sample.jpg';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable('2024-05-05T12:34:56.123+00:00'), null),
        );

        $provider = new ExifMetadataProvider($metadataExtractor);

        $captureDateTime = $provider->getCaptureDateTime(new SplFileInfo($path));

        self::assertInstanceOf(DateTimeInterface::class, $captureDateTime);
        self::assertSame('2024:05:05 12:34:56', $captureDateTime->format('Y:m:d H:i:s'));
        self::assertSame('123000', $captureDateTime->format('u'));
        self::assertNull($provider->getContentIdentifier(new SplFileInfo($path)));
    }

    /**
     * Verifies that both `getCaptureDateTime()` and `getContentIdentifier()`
     * return null when no relevant metadata is found in the file. This is
     * crucial for graceful fallbacks during the grouping phase.
     */
    #[Test]
    public function itReturnsNullWhenMetadataMissing(): void
    {
        $path              = '/tmp/missing.jpg';
        $metadataExtractor = new StubMetadataExtractor();

        $provider = new ExifMetadataProvider($metadataExtractor);

        self::assertNull($provider->getCaptureDateTime(new SplFileInfo($path)));
        self::assertNull($provider->getContentIdentifier(new SplFileInfo($path)));
    }

    /**
     * Verifies that microsecond precision (up to 6 fractional digits) is
     * preserved during extraction. This allows distinguishing between photos
     * taken in rapid succession (burst mode) that would otherwise share
     * the same timestamp.
     */
    #[Test]
    public function itPreservesMicrosecondPrecision(): void
    {
        $path              = '/tmp/video_micro.mov';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable('2024-05-05T12:34:56.123456+00:00'), null),
        );

        $provider = new ExifMetadataProvider($metadataExtractor);

        $captureDateTime = $provider->getCaptureDateTime(new SplFileInfo($path));

        self::assertInstanceOf(DateTimeInterface::class, $captureDateTime);
        self::assertSame('2024:05:05 12:34:56', $captureDateTime->format('Y:m:d H:i:s'));
        self::assertSame('123456', $captureDateTime->format('u'));
    }

    /**
     * Verifies that the Live Photo content identifier is extracted and lowercased
     * even when the capture date is absent.
     *
     * MOV companions in Live Photos often have no EXIF date but always carry the
     * Apple content identifier. This test ensures they are still discoverable
     * by the pairing service without requiring a valid capture date.
     */
    #[Test]
    public function itExtractsLivePhotoIdWhenCaptureDateMissing(): void
    {
        $path              = '/tmp/live.mov';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new TemporalMetadata(null, 'UUID-5678'));

        $provider = new ExifMetadataProvider($metadataExtractor);

        self::assertNull($provider->getCaptureDateTime(new SplFileInfo($path)));

        $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

        self::assertSame('uuid-5678', $identifier);
    }

    /**
     * Verifies that the content identifier is lowercased and whitespace-trimmed.
     *
     * Different extraction tools may produce identifiers with varying case and
     * leading/trailing whitespace. Normalisation ensures that the still image
     * and its video companion always match.
     */
    #[Test]
    public function itNormalisesLivePhotoIdentifierCasing(): void
    {
        $path              = '/tmp/live-photo.jpg';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new TemporalMetadata(null, '  LivePhoto-ID '));

        $provider = new ExifMetadataProvider($metadataExtractor);

        $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

        self::assertSame('livephoto-id', $identifier);
    }

    /**
     * Verifies that an ExifMetadataReadException from the extractor is re-thrown
     * as-is, preserving its specific type for callers that distinguish metadata
     * read failures from other TargetFilenameException subtypes.
     */
    #[Test]
    public function itPreservesExifMetadataReadExceptionType(): void
    {
        $path              = '/tmp/error.jpg';
        $original          = new ExifMetadataReadException('failure');
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, $original);

        $provider = new ExifMetadataProvider($metadataExtractor);

        $this->expectException(ExifMetadataReadException::class);
        $this->expectExceptionMessage('failure');

        $provider->getCaptureDateTime(new SplFileInfo($path));
    }

    /**
     * Verifies that when a persistent MetadataCache is set and contains a valid
     * entry, the extractor is NOT called.
     *
     * This test ensures that the provider correctly respects the persistent
     * cache, skipping expensive metadata extraction via Exiftool if the
     * data is already known from a previous run.
     */
    #[Test]
    public function itSkipsExtractionOnPersistentCacheHit(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('provider_cache_', true);

        if (!mkdir($workspace, 0o755, true) && !is_dir($workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        $filePath  = $workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        $cacheFile = $workspace . DIRECTORY_SEPARATOR . 'metadata-cache.php';

        file_put_contents($filePath, 'fake image data');

        try {
            $file = new SplFileInfo($filePath);

            // Pre-populate the persistent cache
            $cache = new MetadataCache($cacheFile, new Filesystem());
            $cache->set($file, new TemporalMetadata(
                new DateTimeImmutable('2024-05-05T12:34:56+02:00'),
                'uuid-1234',
            ));
            $cache->flush();

            // Create a fresh cache instance that loads from disk
            $freshCache = new MetadataCache($cacheFile, new Filesystem());

            // The extractor should NOT be called — we verify by not registering a response
            $metadataExtractor = new StubMetadataExtractor();

            $provider = new ExifMetadataProvider($metadataExtractor);
            $provider->setCache($freshCache);

            $captureDateTime = $provider->getCaptureDateTime($file);

            self::assertInstanceOf(DateTimeInterface::class, $captureDateTime);
            self::assertSame('2024:05:05 12:34:56', $captureDateTime->format('Y:m:d H:i:s'));

            $contentId = $provider->getContentIdentifier($file);

            self::assertSame('uuid-1234', $contentId);
        } finally {
            @unlink($filePath);

            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }

            @rmdir($workspace);
        }
    }

    /**
     * Verifies that on a persistent cache miss, the extractor is called and the
     * result is stored in the persistent cache.
     *
     * This ensures that new files are correctly analyzed and their metadata
     * is persisted, allowing subsequent runs or pairwise comparisons to
     * benefit from the cached results.
     */
    #[Test]
    public function itStoresExtractionResultInPersistentCacheOnMiss(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('provider_store_', true);

        if (!mkdir($workspace, 0o755, true) && !is_dir($workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        $filePath  = $workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        $cacheFile = $workspace . DIRECTORY_SEPARATOR . 'metadata-cache.php';

        file_put_contents($filePath, 'image content');

        try {
            $file = new SplFileInfo($filePath);

            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $filePath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-08-20T15:00:00.000+00:00'),
                    'live-uuid',
                ),
            );

            $cache    = new MetadataCache($cacheFile, new Filesystem());
            $provider = new ExifMetadataProvider($metadataExtractor);
            $provider->setCache($cache);

            // First call: cache miss, extractor is called, result stored
            $captureDateTime = $provider->getCaptureDateTime($file);

            self::assertInstanceOf(DateTimeInterface::class, $captureDateTime);

            $cache->flush();

            // Load from disk — the entry should be present
            $freshCache = new MetadataCache($cacheFile, new Filesystem());
            $entry      = $freshCache->get($file);

            self::assertNotNull($entry);
            self::assertSame('2024-08-20T15:00:00.000000+00:00', $entry->getCaptureDateTime()?->format('Y-m-d\TH:i:s.uP'));
            self::assertSame('live-uuid', $entry->getContentId());
        } finally {
            @unlink($filePath);

            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }

            @rmdir($workspace);
        }
    }

    /**
     * Verifies that clearCache() only clears the in-memory cache, NOT the
     * persistent MetadataCache. After clearCache(), the provider should still
     * find the entry in the persistent cache on the next access.
     */
    #[Test]
    public function clearCacheOnlyClearsInMemoryCacheNotPersistentCache(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('provider_clear_', true);

        if (!mkdir($workspace, 0o755, true) && !is_dir($workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        $filePath  = $workspace . DIRECTORY_SEPARATOR . 'photo.jpg';
        $cacheFile = $workspace . DIRECTORY_SEPARATOR . 'metadata-cache.php';

        file_put_contents($filePath, 'image bytes');

        try {
            $file = new SplFileInfo($filePath);

            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $filePath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-03-10T09:00:00.000+00:00'),
                    null,
                ),
            );

            $cache    = new MetadataCache($cacheFile, new Filesystem());
            $provider = new ExifMetadataProvider($metadataExtractor);
            $provider->setCache($cache);

            // Populate both caches
            $provider->getCaptureDateTime($file);

            // Clear only the in-memory cache
            $provider->clearCache();

            // Remove the stub response so the extractor would return null if called
            $metadataExtractor->withResponse($filePath, null);

            // Should still get the date from the persistent cache
            $captureDateTime = $provider->getCaptureDateTime($file);

            self::assertInstanceOf(DateTimeInterface::class, $captureDateTime);
            self::assertSame('2024:03:10 09:00:00', $captureDateTime->format('Y:m:d H:i:s'));
        } finally {
            @unlink($filePath);

            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }

            @rmdir($workspace);
        }
    }

    /**
     * Verifies that `hasReliableDateTime()` returns true when raw metadata matches
     * the filename date, even if the timezone is marked as ambiguous.
     *
     * For some video formats, the timezone is uncertain, but if the local time
     * in the filename matches the UTC time in the metadata, we assume the
     * date is reliable enough for naming.
     */
    #[Test]
    public function hasReliableDateTimeReturnsTrueWhenRawMatchesFilename(): void
    {
        $filePath = sys_get_temp_dir() . '/2012-06-16_15-45-34-000.mp4';
        file_put_contents($filePath, 'test');

        try {
            $extractor = new StubMetadataExtractor();
            $extractor->withResponse(
                $filePath,
                new TemporalMetadata(
                    new DateTimeImmutable('2012-06-16T15:45:34+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $provider = new ExifMetadataProvider($extractor);

            self::assertTrue($provider->hasReliableDateTime(new SplFileInfo($filePath)));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Verifies that `hasReliableDateTime()` returns false when the timezone is
     * ambiguous and the raw metadata does NOT match the filename date.
     *
     * If the filename says one thing and the metadata says another (e.g. shifted
     * by a few hours), and the timezone is already marked as unreliable, we
     * cannot trust the date for renaming without user intervention or fallback.
     */
    #[Test]
    public function hasReliableDateTimeReturnsFalseWhenAmbiguousAndMismatch(): void
    {
        $filePath = sys_get_temp_dir() . '/2012-06-16_15-45-34-000.mp4';
        file_put_contents($filePath, 'test');

        try {
            $extractor = new StubMetadataExtractor();
            $extractor->withResponse(
                $filePath,
                new TemporalMetadata(
                    new DateTimeImmutable('2012-06-16T13:45:34+00:00'), // 2h off
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $provider = new ExifMetadataProvider($extractor);

            self::assertFalse($provider->hasReliableDateTime(new SplFileInfo($filePath)));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Verifies that hasReliableDateTime returns false when the seconds differ
     * between metadata and filename, even though minutes match (Fix 3).
     */
    #[Test]
    public function hasReliableDateTimeReturnsFalseWhenSecondsDiffer(): void
    {
        // Filename says :34, metadata says :00 — same minute, different second.
        $filePath = sys_get_temp_dir() . '/2012-06-16_15-45-34-000.mp4';
        file_put_contents($filePath, 'test');

        try {
            $extractor = new StubMetadataExtractor();
            $extractor->withResponse(
                $filePath,
                new TemporalMetadata(
                    new DateTimeImmutable('2012-06-16T15:45:00+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $provider = new ExifMetadataProvider($extractor);

            self::assertFalse($provider->hasReliableDateTime(new SplFileInfo($filePath)));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Verifies that hasReliableDateTime returns true for normal files
     * without any flags.
     */
    #[Test]
    public function hasReliableDateTimeReturnsTrueForNormalFile(): void
    {
        $filePath = sys_get_temp_dir() . '/2024-01-15_10-30-00.jpg';
        file_put_contents($filePath, 'test');

        try {
            $extractor = new StubMetadataExtractor();
            $extractor->withResponse(
                $filePath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T10:30:00+00:00'),
                    null,
                ),
            );

            $provider = new ExifMetadataProvider($extractor);

            self::assertTrue($provider->hasReliableDateTime(new SplFileInfo($filePath)));
        } finally {
            @unlink($filePath);
        }
    }
}
