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

use function strtolower;
use function trim;

/**
 * Immutable value object holding the temporal metadata extracted from a media
 * file: the capture timestamp (with potential microsecond precision) and the
 * optional Apple Live Photo content identifier. Additional camera, location,
 * and Live-Photo-specific markers are included so downstream heuristics can
 * reason about ambiguous still/video pairings without reparsing the file.
 *
 * Produced by MetadataExtractor and cached by ExifMetadataProvider for use
 * throughout the rename pipeline. Mutable input timestamps are converted to
 * immutable values at construction and capture-time replacement, preventing
 * caller-owned DateTime objects from changing cached or grouped metadata.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TemporalMetadata
{
    use MetadataSignalAccessorsTrait;

    /**
     * Immutable capture timestamp, detached from mutable caller-owned inputs.
     */
    private ?DateTimeImmutable $captureDateTime;

    /**
     * Immutable raw QuickTime provenance, independent of capture-time updates.
     */
    private ?DateTimeImmutable $rawQuickTimeCreateDate;

    /**
     * Stores metadata signals and snapshots mutable timestamps without changing
     * their timezone or precision. Immutable timestamps are retained by identity.
     *
     * @param DateTimeInterface|null $captureDateTime             Date and time the photo/video was captured, with
     *                                                            potential microsecond precision from EXIF SubSecTime
     * @param string|null            $livePhotoId                 Apple Live Photo content identifier linking the
     *                                                            still image to its companion video
     * @param bool                   $isFallbackDateTime          Whether the capture date was derived from the
     *                                                            fallback DateTime tag (0x0132) instead of
     *                                                            DateTimeOriginal (0x9003) or CreateDate (0x9004)
     * @param bool                   $isAmbiguousTimezone         Whether the timezone could not be determined
     *                                                            (file modification time altered, cannot distinguish
     *                                                            UTC from local time)
     * @param int|null               $livePhotoVideoIndex         Apple still-side Live Photo index from maker notes
     * @param string|null            $cameraMake                  Camera manufacturer (e.g. Apple)
     * @param string|null            $cameraModel                 Camera model (e.g. iPhone 8)
     * @param string|null            $software                    Device software/firmware version
     * @param float|null             $latitude                    Signed GPS latitude in decimal degrees
     * @param float|null             $longitude                   Signed GPS longitude in decimal degrees
     * @param float|null             $videoDurationSeconds        Video duration in seconds for MOV/MP4 assets
     * @param bool                   $hasQuickTimeLivePhotoMarker True when the QuickTime container exposes
     *                                                            Live Photo marker keys such as still-image-time
     *                                                            or live-photo-info
     * @param DateTimeInterface|null $rawQuickTimeCreateDate      Raw QuickTime CreateDate atom value (UTC, no
     *                                                            timezone resolution). Used by --force to bypass
     *                                                            the resolved Keys:CreationDate.
     */
    public function __construct(
        ?DateTimeInterface $captureDateTime,
        private ?string $livePhotoId,
        private bool $isFallbackDateTime = false,
        private bool $isAmbiguousTimezone = false,
        private ?int $livePhotoVideoIndex = null,
        private ?string $cameraMake = null,
        private ?string $cameraModel = null,
        private ?string $software = null,
        private ?float $latitude = null,
        private ?float $longitude = null,
        private ?float $videoDurationSeconds = null,
        private bool $hasQuickTimeLivePhotoMarker = false,
        ?DateTimeInterface $rawQuickTimeCreateDate = null,
    ) {
        $this->captureDateTime        = $this->normalizeDateTime($captureDateTime);
        $this->rawQuickTimeCreateDate = $this->normalizeDateTime($rawQuickTimeCreateDate);
    }

    /**
     * Returns the capture timestamp.
     *
     * @return DateTimeImmutable|null The immutable capture date and time, or null if missing.
     */
    public function getCaptureDateTime(): ?DateTimeImmutable
    {
        return $this->captureDateTime;
    }

    /**
     * Returns the raw QuickTime CreateDate atom value (UTC).
     *
     * This value is returned without Keys:CreationDate resolution. Returns null
     * for non-QuickTime files or when not populated.
     *
     * @return DateTimeImmutable|null The immutable raw QuickTime CreateDate.
     */
    public function getRawQuickTimeCreateDate(): ?DateTimeImmutable
    {
        return $this->rawQuickTimeCreateDate;
    }

    /**
     * Returns the raw Apple Live Photo content identifier string.
     *
     * @return string|null The identifier, or null when not part of a Live Photo pair.
     */
    public function getLivePhotoId(): ?string
    {
        return $this->livePhotoId;
    }

    /**
     * Returns whether the capture date was derived from the fallback DateTime tag.
     *
     * Fallback tags are 0x0132 (DateTime) instead of 0x9003 (DateTimeOriginal)
     * or 0x9004 (CreateDate).
     *
     * @return bool True if a fallback tag was used.
     */
    public function isFallbackDateTime(): bool
    {
        return $this->isFallbackDateTime;
    }

    /**
     * Returns a new instance with the capture date updated.
     * Used when the original date is missing or needs manual correction.
     * Native clone-with preserves every independent field, including raw
     * QuickTime provenance and future metadata additions, without repeating the
     * constructor's field list. Normalize the replacement explicitly because
     * clone-with does not execute the constructor's timestamp normalization.
     *
     * @param DateTimeInterface $captureDateTime New capture date
     *
     * @return TemporalMetadata Copy retaining all other metadata and the original instance
     */
    public function withCaptureDateTime(DateTimeInterface $captureDateTime): self
    {
        return clone ($this, ['captureDateTime' => $this->normalizeDateTime($captureDateTime)]);
    }

    /**
     * Detaches mutable timestamps at the DTO boundary without reparsing dates,
     * preserving named timezones and microseconds. Null and already immutable
     * inputs are retained directly to avoid unnecessary allocations.
     *
     * @param DateTimeInterface|null $dateTime Timestamp supplied by a caller
     *
     * @return DateTimeImmutable|null Immutable snapshot, original immutable input, or null
     */
    private function normalizeDateTime(?DateTimeInterface $dateTime): ?DateTimeImmutable
    {
        if (!$dateTime instanceof DateTimeInterface) {
            return null;
        }

        return $dateTime instanceof DateTimeImmutable
            ? $dateTime
            : DateTimeImmutable::createFromInterface($dateTime);
    }

    /**
     * Returns true if the metadata indicates this is a still image of a Live Photo.
     * Checks for either a Content Identifier (UUID) or a still-side video index.
     */
    public function hasStillLivePhotoMarker(): bool
    {
        if ($this->normalizeString($this->livePhotoId) !== null) {
            return true;
        }

        return $this->livePhotoVideoIndex !== null;
    }

    /**
     * Returns true if the metadata indicates this is the video component of a Live Photo.
     * Checks for either a Content Identifier (UUID) or specialized QuickTime markers.
     */
    public function hasVideoLivePhotoMarker(): bool
    {
        if ($this->normalizeString($this->livePhotoId) !== null) {
            return true;
        }

        return $this->hasQuickTimeLivePhotoMarker;
    }

    /**
     * Returns the normalized Live Photo content identifier.
     *
     * Strips leading/trailing whitespace and converts to lowercase.
     *
     * @return string|null Normalized content ID, or null if absent.
     */
    public function getNormalizedLivePhotoId(): ?string
    {
        return $this->normalizeString($this->livePhotoId);
    }

    /**
     * Returns true if either the camera make or model is present, allowing
     * for device-based grouping or similarity scoring.
     */
    public function hasComparableDeviceIdentity(): bool
    {
        if ($this->normalizeString($this->cameraMake) !== null) {
            return true;
        }

        if ($this->normalizeString($this->cameraModel) !== null) {
            return true;
        }

        return $this->normalizeString($this->software) !== null;
    }

    /**
     * Returns a combined key of make, model, and software for device identification.
     *
     * Used for grouping assets from the same source.
     *
     * @return string The combined device key.
     */
    public function getNormalizedDeviceKey(): string
    {
        return ($this->normalizeString($this->cameraMake) ?? '')
            . '|'
            . ($this->normalizeString($this->cameraModel) ?? '')
            . '|'
            . ($this->normalizeString($this->software) ?? '');
    }

    /**
     * Internal helper to normalize strings by trimming and lowercasing.
     */
    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return $normalized !== '' ? $normalized : null;
    }
}
