<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Service\MetadataCache;
use SplFileInfo;

use function array_key_exists;
use function is_string;
use function strtolower;
use function trim;

/**
 * Thin caching layer over the MetadataExtractor that provides direct access to
 * the capture timestamp and Live Photo content identifier. Maintains a per-file
 * pathname-keyed cache so each file is extracted at most once even when queried
 * from multiple pipeline stages.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class ExifMetadataProvider
{
    /**
     * Per-file cache of extracted temporal metadata (null when the file lacks usable metadata).
     *
     * @var array<string, TemporalMetadata|null>
     */
    private array $metadataCache = [];

    /**
     * Timezone used to convert UTC timestamps from video files that lack explicit
     * timezone metadata (e.g. QuickTime/MP4). Null means no conversion is applied.
     */
    private ?DateTimeZone $defaultTimezone = null;

    /**
     * Optional persistent disk cache for metadata extraction results. When set,
     * extraction results survive across runs so unchanged files are never re-read.
     */
    private ?MetadataCache $cache = null;

    /**
     * @param MetadataExtractorInterface $metadataExtractor Underlying extractor for reading file metadata
     */
    public function __construct(private readonly MetadataExtractorInterface $metadataExtractor)
    {
    }

    /**
     * Sets the default timezone for converting UTC timestamps from video files
     * without explicit timezone metadata. Pass null to disable conversion.
     */
    public function setDefaultTimezone(?DateTimeZone $defaultTimezone): void
    {
        $this->defaultTimezone = $defaultTimezone;
    }

    /**
     * Sets the persistent disk cache for metadata extraction results. When set,
     * extraction results are cached to disk and survive across process runs.
     * Pass null to disable persistent caching.
     */
    public function setCache(?MetadataCache $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Releases all cached metadata to free memory.
     * Safe to call after the grouping phase when all content identifiers
     * have been captured into the content identifier map.
     */
    public function clearCache(): void
    {
        $this->metadataCache = [];
    }

    /**
     * Returns the capture timestamp for the given file, extracting and caching
     * metadata on first access. Returns null when the file contains no usable
     * capture date information.
     *
     * When the metadata indicates a UTC timestamp without explicit timezone info
     * (typical for QuickTime/MP4 files) and a default timezone is configured,
     * the timestamp is converted to the configured local timezone.
     *
     * @param SplFileInfo $splFileInfo File to extract metadata from
     *
     * @return DateTimeInterface|null Capture timestamp with potential microsecond precision,
     *                                or null when unavailable
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function getCaptureDateTime(SplFileInfo $splFileInfo): ?DateTimeInterface
    {
        $metadata = $this->resolveMetadata($splFileInfo);
        $dateTime = $metadata?->getCaptureDateTime();

        if (
            ($dateTime instanceof DateTimeInterface)
            && $metadata->isUtcWithoutTimezone()
            && ($this->defaultTimezone instanceof DateTimeZone)
        ) {
            return DateTimeImmutable::createFromInterface($dateTime)
                ->setTimezone($this->defaultTimezone);
        }

        return $dateTime;
    }

    /**
     * Returns whether the given file has an ambiguous timezone — the QuickTime
     * timestamp could be UTC or local time but we cannot determine which.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function isAmbiguousTimezone(SplFileInfo $splFileInfo): bool
    {
        return $this->resolveMetadata($splFileInfo)?->isAmbiguousTimezone() ?? false;
    }

    /**
     * Returns whether the capture date for the given file was derived from
     * the fallback DateTime tag (0x0132) instead of DateTimeOriginal/CreateDate.
     *
     * @param SplFileInfo $splFileInfo File to query
     *
     * @return bool True when the date came from the fallback tag, false otherwise
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function isFallbackDateTime(SplFileInfo $splFileInfo): bool
    {
        return $this->resolveMetadata($splFileInfo)?->isFallbackDateTime() ?? false;
    }

    /**
     * Returns the normalized Live Photo content identifier for the given file.
     * Triggers metadata extraction if not yet cached.
     *
     * The identifier is lowercased and trimmed for case-insensitive companion
     * detection between photo and video assets.
     *
     * @param SplFileInfo $splFileInfo File to query
     *
     * @return string|null Lowercased, trimmed content identifier, or null when
     *                     the file is not part of an Apple Live Photo pair
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function getContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        $metadata = $this->resolveMetadata($splFileInfo);

        if (!$metadata instanceof TemporalMetadata) {
            return null;
        }

        return $this->normalizeContentIdentifier($metadata->getLivePhotoId());
    }

    /**
     * Extracts and caches temporal metadata for the given file. Returns the cached
     * result on subsequent calls for the same pathname. When a persistent disk cache
     * is configured, it is checked before invoking the metadata extractor, and
     * extraction results are stored in it for future runs.
     *
     * @param SplFileInfo $splFileInfo File to extract metadata from
     *
     * @return TemporalMetadata|null Extracted metadata, or null when no relevant fields exist
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    private function resolveMetadata(SplFileInfo $splFileInfo): ?TemporalMetadata
    {
        $key = $splFileInfo->getPathname();

        if (!array_key_exists($key, $this->metadataCache)) {
            // Check persistent cache first
            if ($this->cache instanceof MetadataCache) {
                $cached = $this->cache->get($splFileInfo);

                if ($cached !== null) {
                    $this->metadataCache[$key] = $this->reconstructFromCache($cached);

                    return $this->metadataCache[$key];
                }
            }

            try {
                $this->metadataCache[$key] = $this->metadataExtractor->extractTemporalMetadata($splFileInfo);
            } catch (ExifMetadataReadException $exception) {
                // Cache null so subsequent calls (e.g. Live Photo pairing) return
                // gracefully instead of re-throwing.
                $this->metadataCache[$key] = null;

                throw $exception;
            }

            // Store in persistent cache
            if ($this->cache instanceof MetadataCache) {
                $meta = $this->metadataCache[$key];

                $this->cache->set(
                    $splFileInfo,
                    $meta?->getCaptureDateTime()?->format('Y-m-d\TH:i:s.uP'),
                    $meta?->getLivePhotoId(),
                    $meta?->isFallbackDateTime() ?? false,
                    $meta?->isUtcWithoutTimezone() ?? false,
                    $meta?->isAmbiguousTimezone() ?? false,
                );
            }
        }

        return $this->metadataCache[$key];
    }

    /**
     * Reconstructs a TemporalMetadata instance from a persistent cache entry.
     * Returns null when the cached entry represents a file with no usable metadata.
     *
     * @param array{mtime: int, size: int, captureDateTime: string|null, contentId: string|null, isFallback: bool, isUtcWithoutTimezone: bool, isAmbiguousTimezone?: bool} $cached
     *
     * @return TemporalMetadata|null Reconstructed metadata, or null when the cache entry has no date or content ID
     */
    private function reconstructFromCache(array $cached): ?TemporalMetadata
    {
        $dateTime = null;

        if (is_string($cached['captureDateTime'])) {
            try {
                $dateTime = new DateTimeImmutable($cached['captureDateTime']);
            } catch (DateMalformedStringException) {
                return null;
            }
        }

        $contentId = $cached['contentId'];

        if ((!$dateTime instanceof DateTimeImmutable) && ($contentId === null)) {
            return null;
        }

        return new TemporalMetadata(
            $dateTime,
            $contentId,
            $cached['isFallback'],
            $cached['isUtcWithoutTimezone'],
            $cached['isAmbiguousTimezone'] ?? false,
        );
    }

    /**
     * Lowercases and trims a Live Photo content identifier string. Returns null
     * for null, empty, or whitespace-only inputs.
     *
     * @param string|null $contentIdentifier Raw content identifier from TemporalMetadata
     *
     * @return string|null Normalized identifier, or null when not present
     */
    private function normalizeContentIdentifier(?string $contentIdentifier): ?string
    {
        if (($contentIdentifier === null) || ($contentIdentifier === '')) {
            return null;
        }

        $normalized = strtolower(trim($contentIdentifier));

        return $normalized !== '' ? $normalized : null;
    }
}
