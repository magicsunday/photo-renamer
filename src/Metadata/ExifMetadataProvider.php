<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use SplFileInfo;

use function array_key_exists;
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

        if (($dateTime instanceof DateTimeInterface)
            && $metadata->isUtcWithoutTimezone()
            && ($this->defaultTimezone instanceof DateTimeZone)) {
            return DateTimeImmutable::createFromInterface($dateTime)
                ->setTimezone($this->defaultTimezone);
        }

        return $dateTime;
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
     * result on subsequent calls for the same pathname.
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
            try {
                $this->metadataCache[$key] = $this->metadataExtractor->extractTemporalMetadata($splFileInfo);
            } catch (ExifMetadataReadException $exception) {
                // Cache null so subsequent calls (e.g. Live Photo pairing) return
                // gracefully instead of re-throwing.
                $this->metadataCache[$key] = null;

                throw $exception;
            }
        }

        return $this->metadataCache[$key];
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
