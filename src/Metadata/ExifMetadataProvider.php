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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Service\MetadataCache;
use SplFileInfo;

use function array_key_exists;
use function is_string;

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
     * When the metadata indicates an ambiguous timezone (typical for QuickTime/MP4
     * files without explicit timezone info) and a default timezone is configured,
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
        $metadata = $this->getTemporalMetadata($splFileInfo);

        return $metadata?->getCaptureDateTime();
    }

    /**
     * Returns the raw capture timestamp without timezone conversion. Used by
     * write-date to preserve the original time when resolving timezone ambiguity
     * (non-Apple cameras store local time as UTC in QuickTime containers).
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function getRawCaptureDateTime(SplFileInfo $splFileInfo): ?DateTimeInterface
    {
        return $this->resolveMetadata($splFileInfo)?->getCaptureDateTime();
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
     * Returns whether the file has a reliable capture date. A date is reliable when:
     * - It is not a fallback (0x0132) AND not ambiguous timezone, OR
     * - The raw metadata date matches the filename date (file was already fixed).
     *
     * This is the single source of truth for "should we flag this file as problematic?"
     * Used by rename:exif ([W]/[F] tags), rename:verify, and rename:write-date.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function hasReliableDateTime(SplFileInfo $splFileInfo): bool
    {
        $metadata = $this->resolveMetadata($splFileInfo);

        if (!$metadata instanceof TemporalMetadata) {
            return false;
        }

        // No issues → reliable
        if ((!$metadata->isFallbackDateTime()) && (!$metadata->isAmbiguousTimezone())) {
            return true;
        }

        // Issue present, but raw metadata matches filename → already fixed → reliable
        $rawDateTime      = $metadata->getCaptureDateTime();
        $filenameDateTime = FileHelper::extractDateTimeFromPath($splFileInfo->getPathname());

        return ($rawDateTime instanceof DateTimeInterface)
        && ($filenameDateTime instanceof DateTimeImmutable)
        && ($rawDateTime->format('Y-m-d H:i:s') === $filenameDateTime->format('Y-m-d H:i:s'));
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
        return $this->resolveMetadata($splFileInfo)?->getNormalizedLivePhotoId();
    }

    /**
     * Returns the full temporal metadata payload for the file, including
     * additional camera/location/live-photo fields used by conflict heuristics.
     *
     * The returned capture timestamp is adjusted in the same way as
     * {@see getCaptureDateTime()} so consumers compare the effective local
     * capture times seen by the rest of the pipeline.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function getTemporalMetadata(SplFileInfo $splFileInfo): ?TemporalMetadata
    {
        $metadata = $this->resolveMetadata($splFileInfo);

        if (!$metadata instanceof TemporalMetadata) {
            return null;
        }

        return $this->applyConfiguredTimezone($metadata, $splFileInfo);
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
                $this->cache->set($splFileInfo, $this->metadataCache[$key]);
            }
        }

        return $this->metadataCache[$key];
    }

    /**
     * Reconstructs a TemporalMetadata instance from a persistent cache entry.
     * Returns null when the cached entry represents a file with no usable metadata.
     *
     * @param array{
     *     mtime: int,
     *     size: int,
     *     captureDateTime: string|null,
     *     contentId: string|null,
     *     isFallback: bool,
     *     isAmbiguousTimezone: bool,
     *     livePhotoVideoIndex?: int|null,
     *     cameraMake?: string|null,
     *     cameraModel?: string|null,
     *     software?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     videoDurationSeconds?: float|null,
     *     hasQuickTimeLivePhotoMarker?: bool
     * } $cached
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
            $cached['isAmbiguousTimezone'],
            $cached['livePhotoVideoIndex'] ?? null,
            $cached['cameraMake'] ?? null,
            $cached['cameraModel'] ?? null,
            $cached['software'] ?? null,
            $cached['latitude'] ?? null,
            $cached['longitude'] ?? null,
            $cached['videoDurationSeconds'] ?? null,
            $cached['hasQuickTimeLivePhotoMarker'] ?? false,
        );
    }

    private function applyConfiguredTimezone(TemporalMetadata $metadata, SplFileInfo $splFileInfo): TemporalMetadata
    {
        $dateTime = $metadata->getCaptureDateTime();

        if (
            !($dateTime instanceof DateTimeInterface)
            || !$metadata->isAmbiguousTimezone()
            || !($this->defaultTimezone instanceof DateTimeZone)
            || $this->hasReliableDateTime($splFileInfo)
        ) {
            return $metadata;
        }

        return $metadata->withCaptureDateTime(
            DateTimeImmutable::createFromInterface($dateTime)
                ->setTimezone($this->defaultTimezone),
        );
    }
}
