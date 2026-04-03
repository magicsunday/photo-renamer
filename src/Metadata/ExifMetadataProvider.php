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
use SplFileInfo;

use function array_key_exists;
use function is_string;

/**
 * Thin caching layer over the MetadataExtractor that provides unified access
 * to capture timestamps and Live Photo content identifiers.
 *
 * This provider implements two levels of caching:
 * 1. An in-memory cache ($metadataCache) for the current process run, ensuring
 *    that each file is analyzed at most once even if accessed by multiple
 *    pipeline stages (e.g., grouping and target resolution).
 * 2. An optional persistent disk cache (via MetadataCache) that survives across
 *    different command executions, avoiding expensive re-reads for unchanged files.
 *
 * It also handles timezone normalization for video files (QuickTime/MP4) which
 * often store timestamps in UTC without explicit offset information.
 */
final class ExifMetadataProvider
{
    /**
     * Per-file in-memory cache of extracted temporal metadata.
     * Maps the absolute file pathname to the metadata object or null if extraction failed.
     *
     * @var array<string, TemporalMetadata|null>
     */
    private array $metadataCache = [];

    /**
     * Default timezone used for normalizing UTC timestamps from video containers.
     * This is applied when the metadata indicates an ambiguous timezone (e.g., QuickTime
     * 'CreationDate' without offset).
     */
    private ?DateTimeZone $defaultTimezone = null;

    /**
     * Persistent disk cache service. If set, metadata results are persisted
     * to disk based on file modification time and size to detect changes.
     */
    private ?MetadataCache $cache = null;

    /**
     * @param MetadataExtractorInterface $metadataExtractor The strategy used to actually parse the file bits
     */
    public function __construct(private readonly MetadataExtractorInterface $metadataExtractor)
    {
    }

    /**
     * Configures the local timezone for video UTC normalization.
     *
     * Video files (MP4/MOV) often store timestamps in UTC. To match the local
     * wall-clock time shown in photo viewers, this timezone is used as a fallback
     * when no explicit offset is present in the file metadata.
     */
    public function setDefaultTimezone(?DateTimeZone $defaultTimezone): void
    {
        $this->defaultTimezone = $defaultTimezone;
    }

    /**
     * Attaches a persistent disk cache to speed up subsequent runs.
     *
     * When enabled, the provider first checks the disk cache for a matching
     * file entry (mtime/size check). If found, extraction is skipped entirely.
     */
    public function setCache(?MetadataCache $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Clears the internal in-memory cache.
     *
     * This should be called between large batch operations or after the grouping
     * phase to prevent excessive memory consumption when processing tens of
     * thousands of files.
     */
    public function clearCache(): void
    {
        $this->metadataCache = [];
    }

    /**
     * Returns the capture timestamp for the given file, utilizing all cache layers.
     *
     * If the metadata indicates an ambiguous timezone (e.g. QuickTime without offset)
     * and a default timezone is configured, the timestamp is converted from UTC
     * to the configured local timezone to ensure consistent naming across devices.
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return DateTimeInterface|null The resolved capture date/time, or null if missing/unreadable.
     *
     * @throws ExifMetadataReadException When the physical file cannot be read or parsed.
     */
    public function getCaptureDateTime(SplFileInfo $splFileInfo): ?DateTimeInterface
    {
        $metadata = $this->getTemporalMetadata($splFileInfo);

        return $metadata?->getCaptureDateTime();
    }

    /**
     * Returns the raw capture timestamp without timezone conversion.
     *
     * Used by write-date to preserve the original time when resolving timezone
     * ambiguity (non-Apple cameras often store local time as UTC in QuickTime
     * containers).
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return DateTimeInterface|null The raw capture date/time.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
     */
    public function getRawCaptureDateTime(SplFileInfo $splFileInfo): ?DateTimeInterface
    {
        return $this->resolveMetadata($splFileInfo)?->getCaptureDateTime();
    }

    /**
     * Returns the raw QuickTime CreateDate atom value (UTC).
     *
     * This bypasses Keys:CreationDate resolution. Used by --force to read the
     * underlying timestamp when Keys:CreationDate was incorrectly written
     * or is missing.
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return DateTimeInterface|null The raw CreateDate timestamp.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
     */
    public function getRawQuickTimeCreateDate(SplFileInfo $splFileInfo): ?DateTimeInterface
    {
        return $this->resolveMetadata($splFileInfo)?->getRawQuickTimeCreateDate();
    }

    /**
     * Returns whether the given file has an ambiguous timezone.
     *
     * A timezone is ambiguous when the QuickTime timestamp could be either
     * UTC or local time and we cannot determine which due to missing offset info.
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return bool True if the timezone is ambiguous.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
     */
    public function isAmbiguousTimezone(SplFileInfo $splFileInfo): bool
    {
        return $this->resolveMetadata($splFileInfo)?->isAmbiguousTimezone() ?? false;
    }

    /**
     * Returns whether the capture date for the given file was derived from
     * the fallback DateTime tag (0x0132) instead of DateTimeOriginal/CreateDate.
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return bool True when the date came from the fallback tag, false otherwise.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
     */
    public function isFallbackDateTime(SplFileInfo $splFileInfo): bool
    {
        return $this->resolveMetadata($splFileInfo)?->isFallbackDateTime() ?? false;
    }

    /**
     * Returns whether the file has a reliable capture date.
     *
     * A date is reliable when:
     * - It is not a fallback (0x0132) AND not an ambiguous timezone, OR
     * - The raw metadata date matches the filename date (indicating the file was already fixed).
     *
     * This is the single source of truth for "should we flag this file as problematic?".
     * Used by rename:exif ([W]/[F] tags), rename:verify, and rename:write-date.
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return bool True if the date is considered reliable.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
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
     *
     * Triggers metadata extraction if not yet cached. The identifier is
     * lowercased and trimmed for case-insensitive companion detection
     * between photo and video assets.
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return string|null Lowercased, trimmed content identifier, or null when
     *                     the file is not part of an Apple Live Photo pair.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
     */
    public function getContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        return $this->resolveMetadata($splFileInfo)?->getNormalizedLivePhotoId();
    }

    /**
     * Returns the full temporal metadata payload for the file.
     *
     * This includes additional camera/location/live-photo fields used by conflict
     * heuristics. The returned capture timestamp is adjusted in the same way as
     * {@see getCaptureDateTime()} so consumers compare the effective local
     * capture times seen by the rest of the pipeline.
     *
     * @param SplFileInfo $splFileInfo The file to query.
     *
     * @return TemporalMetadata|null The extracted metadata payload, or null if missing.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
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
     * Extracts and caches temporal metadata for the given file.
     *
     * Returns the cached result on subsequent calls for the same pathname.
     * When a persistent disk cache is configured, it is checked before
     * invoking the metadata extractor, and extraction results are stored
     * in it for future runs.
     *
     * @param SplFileInfo $splFileInfo The file to extract metadata from.
     *
     * @return TemporalMetadata|null Extracted metadata, or null when no relevant fields exist.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails.
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
     *
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
     *     hasQuickTimeLivePhotoMarker?: bool,
     *     rawQuickTimeCreateDate?: string|null
     * } $cached The cached metadata values.
     *
     * @return TemporalMetadata|null Reconstructed metadata, or null when the cache
     *                               entry has no date or content ID.
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

        $rawQtCreateDate = $this->parseCachedDateTime($cached['rawQuickTimeCreateDate'] ?? null);

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
            $rawQtCreateDate,
        );
    }

    /**
     * Parses a date string from the persistent cache.
     *
     * @param mixed $value The value from the cache.
     *
     * @return DateTimeImmutable|null The parsed date, or null if invalid.
     */
    private function parseCachedDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (DateMalformedStringException) {
            return null;
        }
    }

    /**
     * Applies the configured default timezone to ambiguous timestamps.
     *
     * If the metadata is flagged as having an ambiguous timezone (e.g. UTC
     * from QuickTime), it is converted to the configured default timezone.
     *
     * @param TemporalMetadata $metadata    The metadata to adjust.
     * @param SplFileInfo      $splFileInfo The file context.
     *
     * @return TemporalMetadata The adjusted metadata.
     */
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
