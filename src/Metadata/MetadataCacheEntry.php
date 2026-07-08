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
use SplFileInfo;

use function is_int;
use function is_numeric;
use function is_string;

/**
 * Typed persistent-cache entry for metadata extraction results.
 *
 * The metadata cache persists JSON on disk, but callers should not exchange raw
 * array shapes. This value object defines the stable contract between the cache
 * boundary and metadata consumers while still offering explicit serialization
 * helpers for persistence and legacy cache migration.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class MetadataCacheEntry
{
    use MetadataSignalAccessorsTrait;

    /**
     * @param int                    $mtime                       File modification timestamp used for staleness checks.
     * @param int                    $size                        File size in bytes used for staleness checks.
     * @param DateTimeImmutable|null $captureDateTime             Cached capture timestamp when metadata extraction found one.
     * @param string|null            $contentId                   Apple Live Photo content identifier for still/video pairing.
     * @param bool                   $isFallback                  Whether the cached capture timestamp came from fallback metadata.
     * @param bool                   $isAmbiguousTimezone         Whether the cached timestamp has unresolved timezone ambiguity.
     * @param int|null               $livePhotoVideoIndex         Optional Live Photo video index from QuickTime metadata.
     * @param string|null            $cameraMake                  Camera manufacturer metadata used in reporting and heuristics.
     * @param string|null            $cameraModel                 Camera model metadata used in reporting and heuristics.
     * @param string|null            $software                    Software metadata used for provenance reporting.
     * @param float|null             $latitude                    GPS latitude when present.
     * @param float|null             $longitude                   GPS longitude when present.
     * @param float|null             $videoDurationSeconds        Video duration metadata for video comparison heuristics.
     * @param bool                   $hasQuickTimeLivePhotoMarker Whether the file carried the QuickTime Live Photo marker flag.
     * @param DateTimeImmutable|null $rawQuickTimeCreateDate      Raw QuickTime creation timestamp before timezone reinterpretation.
     */
    public function __construct(
        private int $mtime,
        private int $size,
        private ?DateTimeImmutable $captureDateTime,
        private ?string $contentId,
        private bool $isFallback,
        private bool $isAmbiguousTimezone,
        private ?int $livePhotoVideoIndex = null,
        private ?string $cameraMake = null,
        private ?string $cameraModel = null,
        private ?string $software = null,
        private ?float $latitude = null,
        private ?float $longitude = null,
        private ?float $videoDurationSeconds = null,
        private bool $hasQuickTimeLivePhotoMarker = false,
        private ?DateTimeImmutable $rawQuickTimeCreateDate = null,
    ) {
    }

    /**
     * Builds a cache entry from the current file state and extracted metadata.
     *
     * @param SplFileInfo           $file     File whose current size and mtime should be cached.
     * @param TemporalMetadata|null $metadata Extracted temporal metadata or null when nothing usable was found.
     *
     * @return MetadataCacheEntry Typed cache entry ready for in-memory storage.
     */
    public static function fromFileAndMetadata(SplFileInfo $file, ?TemporalMetadata $metadata): self
    {
        return new self(
            $file->getMTime(),
            $file->getSize(),
            self::normalizeDateTime($metadata?->getCaptureDateTime()),
            $metadata?->getLivePhotoId(),
            $metadata?->isFallbackDateTime() ?? false,
            $metadata?->isAmbiguousTimezone() ?? false,
            $metadata?->getLivePhotoVideoIndex(),
            $metadata?->getCameraMake(),
            $metadata?->getCameraModel(),
            $metadata?->getSoftware(),
            $metadata?->getLatitude(),
            $metadata?->getLongitude(),
            $metadata?->getVideoDurationSeconds(),
            $metadata?->hasQuickTimeLivePhotoMarker() ?? false,
            self::normalizeDateTime($metadata?->getRawQuickTimeCreateDate()),
        );
    }

    /**
     * Reconstructs a cache entry from a legacy or current JSON-decoded array.
     *
     * Missing optional keys are defaulted so older cache files continue to load
     * without forcing all callers to understand schema evolution.
     *
     * @param array<string, mixed> $data Raw JSON-decoded cache row.
     *
     * @return MetadataCacheEntry Typed cache entry reconstructed from persisted data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::parseInt($data['mtime'] ?? 0),
            self::parseInt($data['size'] ?? 0),
            self::parseDateTime($data['captureDateTime'] ?? null),
            is_string($data['contentId'] ?? null) ? $data['contentId'] : null,
            (bool) ($data['isFallback'] ?? false),
            (bool) ($data['isAmbiguousTimezone'] ?? false),
            self::parseNullableInt($data['livePhotoVideoIndex'] ?? null),
            is_string($data['cameraMake'] ?? null) ? $data['cameraMake'] : null,
            is_string($data['cameraModel'] ?? null) ? $data['cameraModel'] : null,
            is_string($data['software'] ?? null) ? $data['software'] : null,
            self::parseNullableFloat($data['latitude'] ?? null),
            self::parseNullableFloat($data['longitude'] ?? null),
            self::parseNullableFloat($data['videoDurationSeconds'] ?? null),
            (bool) ($data['hasQuickTimeLivePhotoMarker'] ?? false),
            self::parseDateTime($data['rawQuickTimeCreateDate'] ?? null),
        );
    }

    /**
     * Serializes the entry into the stable JSON payload written to disk.
     *
     * @return array{
     *     mtime: int,
     *     size: int,
     *     captureDateTime: string|null,
     *     contentId: string|null,
     *     isFallback: bool,
     *     isAmbiguousTimezone: bool,
     *     livePhotoVideoIndex: int|null,
     *     cameraMake: string|null,
     *     cameraModel: string|null,
     *     software: string|null,
     *     latitude: float|null,
     *     longitude: float|null,
     *     videoDurationSeconds: float|null,
     *     hasQuickTimeLivePhotoMarker: bool,
     *     rawQuickTimeCreateDate: string|null
     * } Explicit serialization payload for persistent cache storage.
     */
    public function toArray(): array
    {
        return [
            'mtime'                       => $this->mtime,
            'size'                        => $this->size,
            'captureDateTime'             => $this->captureDateTime?->format('Y-m-d\TH:i:s.uP'),
            'contentId'                   => $this->contentId,
            'isFallback'                  => $this->isFallback,
            'isAmbiguousTimezone'         => $this->isAmbiguousTimezone,
            'livePhotoVideoIndex'         => $this->livePhotoVideoIndex,
            'cameraMake'                  => $this->cameraMake,
            'cameraModel'                 => $this->cameraModel,
            'software'                    => $this->software,
            'latitude'                    => $this->latitude,
            'longitude'                   => $this->longitude,
            'videoDurationSeconds'        => $this->videoDurationSeconds,
            'hasQuickTimeLivePhotoMarker' => $this->hasQuickTimeLivePhotoMarker,
            'rawQuickTimeCreateDate'      => $this->rawQuickTimeCreateDate?->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * Returns the cached mtime used to validate entry freshness.
     */
    public function getMtime(): int
    {
        return $this->mtime;
    }

    /**
     * Returns the cached byte size used to validate entry freshness.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Returns the cached capture timestamp.
     */
    public function getCaptureDateTime(): ?DateTimeImmutable
    {
        return $this->captureDateTime;
    }

    /**
     * Returns the cached Live Photo content identifier.
     */
    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    /**
     * Returns whether the cached timestamp came from fallback metadata.
     */
    public function isFallback(): bool
    {
        return $this->isFallback;
    }

    /**
     * Returns the raw QuickTime creation timestamp before timezone interpretation.
     */
    public function getRawQuickTimeCreateDate(): ?DateTimeImmutable
    {
        return $this->rawQuickTimeCreateDate;
    }

    /**
     * Normalizes an arbitrary DateTimeInterface into a cache-stable immutable value.
     *
     * @param DateTimeInterface|null $dateTime Timestamp to normalize before caching.
     *
     * @return DateTimeImmutable|null Immutable timestamp or null when absent.
     */
    private static function normalizeDateTime(?DateTimeInterface $dateTime): ?DateTimeImmutable
    {
        if (!$dateTime instanceof DateTimeInterface) {
            return null;
        }

        return $dateTime instanceof DateTimeImmutable
            ? $dateTime
            : DateTimeImmutable::createFromInterface($dateTime);
    }

    /**
     * Parses an integer-like JSON field into a stable integer value.
     *
     * @param mixed $value Raw decoded JSON field.
     */
    private static function parseInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Parses a nullable integer-like JSON field.
     *
     * @param mixed $value Raw decoded JSON field.
     */
    private static function parseNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Parses a nullable float-like JSON field.
     *
     * @param mixed $value Raw decoded JSON field.
     */
    private static function parseNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Parses an ISO-8601 date string from the cache payload.
     *
     * Invalid cached dates are tolerated and normalized to null so corrupted or
     * legacy payloads do not crash cache consumers.
     *
     * @param mixed $value Raw decoded JSON field.
     */
    private static function parseDateTime(mixed $value): ?DateTimeImmutable
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
}
