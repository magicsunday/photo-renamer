<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use DateTimeInterface;
use DateTimeZone;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use Override;
use SplFileInfo;
use Throwable;

use function sprintf;
use function trim;

/**
 * Extracts capture date and Apple Live Photo content identifier from image/video
 * files via the imagemeta {@see MetadataReader}.
 *
 * This extractor bridges the raw metadata tree provided by 'magicsunday/imagemeta'
 * with the 'renamer' pipeline. It specifically targets:
 * - EXIF: DateTimeOriginal for still images.
 * - QuickTime: Keys:CreationDate or mvhd:CreateDate for videos.
 * - MakerNotes: Apple-specific Live Photo identifiers to pair JPG/HEIC with MOV.
 *
 * It implements a fallback logic where it prefers high-precision creation dates
 * (including timezone offsets if present) but falls back to file modification
 * time (mtime) if no internal metadata is found.
 */
final readonly class MetadataExtractor implements MetadataExtractorInterface
{
    /**
     * @param MetadataReader $metadataReader The underlying library for parsing file structures
     */
    public function __construct(private MetadataReader $metadataReader)
    {
    }

    /**
     * Extracts all relevant temporal and device metadata from the given file.
     *
     * Processes EXIF, IPTC, XMP, and QuickTime metadata tags to build a
     * comprehensive {@see TemporalMetadata} object. It handles specific
     * Apple-proprietary tags for Live Photo identification and attempts
     * to resolve the most accurate capture date by checking multiple
     * standard and non-standard fields.
     *
     * @param SplFileInfo $file The physical file to analyze.
     *
     * @return TemporalMetadata|null The extracted metadata, or null if the file is
     *                               not a supported media type.
     *
     * @throws ExifMetadataReadException When the file is corrupted or unreadable.
     */
    #[Override]
    public function extractTemporalMetadata(SplFileInfo $file): ?TemporalMetadata
    {
        try {
            $metadata = $this->metadataReader->read($file->getPathname());
        } catch (Throwable $exception) {
            throw new ExifMetadataReadException(
                sprintf(
                    'Unable to read image metadata from "%s": %s',
                    $file->getPathname(),
                    $exception->getMessage(),
                ),
                $exception->getCode(),
                previous: $exception,
            );
        }

        $structured  = $metadata->structured();
        $livePhotoId = $this->extractContentIdentifier($metadata);

        $isQuickTimeContainer = $metadata->quickTime instanceof QuickTimeMeta;

        // dateTimeOriginal() returns null when only 0x0132 (ModifyDate) exists
        // (fixed in imagemeta #2287). Critical for fallback detection and HEIC
        // timezone ambiguity detection.
        $hasExifDateTimeOriginal = $metadata->exifDoc?->dateTimeOriginal() instanceof DateTimeInterface;

        [$captureDateTime, $isFallback, $isAmbiguousTimezone] = $this->extractCaptureDateTimeWithFallbackFlag($structured, $isQuickTimeContainer, $hasExifDateTimeOriginal);

        $livePhotoVideoIndex         = $structured->makerNotesApple?->livePhoto?->index;
        $cameraMake                  = $this->normalizeNullable($structured->hardware->camera->make);
        $cameraModel                 = $this->normalizeNullable($structured->hardware->camera->model);
        $software                    = $this->normalizeNullable($structured->hardware->device->software);
        $latitude                    = $structured->locationTime->gps->position?->latitudeSigned;
        $longitude                   = $structured->locationTime->gps->position?->longitudeSigned;
        $videoDurationSeconds        = $this->extractVideoDurationSeconds($metadata);
        $hasQuickTimeLivePhotoMarker = $this->hasQuickTimeLivePhotoMarker($metadata);

        if (
            !($captureDateTime instanceof DateTimeInterface)
            && ($livePhotoId === null)
            && ($livePhotoVideoIndex === null)
            && !$hasQuickTimeLivePhotoMarker
        ) {
            return null;
        }

        // Raw QuickTime CreateDate for --force re-writes (bypasses Keys:CreationDate resolution)
        $rawQuickTimeCreateDate = $isQuickTimeContainer
            ? $structured->locationTime->capture->dateTime
            : null;

        return new TemporalMetadata(
            $captureDateTime,
            $livePhotoId,
            $isFallback,
            $isAmbiguousTimezone,
            $livePhotoVideoIndex,
            $cameraMake,
            $cameraModel,
            $software,
            $latitude,
            $longitude,
            $videoDurationSeconds,
            $hasQuickTimeLivePhotoMarker,
            $rawQuickTimeCreateDate,
        );
    }

    /**
     * Extracts the most appropriate capture date and sets the fallback flag.
     *
     * For images, it prioritizes DateTimeOriginal. For videos (QuickTime/MP4),
     * it prioritizes CreationDate over CreateDate (which is often UTC 1904).
     * If no direct capture date is found, it falls back to FileModifyDate.
     *
     * @param StructuredMetadata $structured              The structured metadata from the reader.
     * @param bool               $isQuickTimeContainer    Whether the file is a MOV/MP4 container.
     * @param bool               $hasExifDateTimeOriginal Whether an EXIF DateTimeOriginal was found.
     *
     * @return array{0: DateTimeInterface|null, 1: bool, 2: bool} A tuple of [captureDateTime, isFallback, isAmbiguousTimezone].
     */
    private function extractCaptureDateTimeWithFallbackFlag(
        StructuredMetadata $structured,
        bool $isQuickTimeContainer,
        bool $hasExifDateTimeOriginal,
    ): array {
        $temporal = $structured->locationTime->temporal;
        $original = $temporal->original;
        $create   = $temporal->create;
        $modify   = $temporal->modify;
        $capture  = $structured->locationTime->capture->dateTime;

        // Prefer original, then create, then capture (which includes 0x0132 fallback).
        $dateTime = $original ?? $create ?? $capture;

        if (!$dateTime instanceof DateTimeInterface) {
            return [null, false, false];
        }

        // Fallback detection: if the EXIF document has no DateTimeOriginal (0x9003),
        // no dedicated create date (0x9004), and the resolved timestamp equals the
        // modify date (0x0132), the date is from the generic DateTime tag — not
        // from DateTimeOriginal or CreateDate.
        // Note: $temporal->original uses dateTimeOriginalBestEffort() which itself
        // falls back to 0x0132, so we must check the EXIF document directly.
        $isFallback = (!$hasExifDateTimeOriginal)
            && (!$create instanceof DateTimeInterface)
            && ($modify instanceof DateTimeInterface)
            && ($dateTime->getTimestamp() === $modify->getTimestamp());

        // QuickTime containers without explicit timezone info (no Keys CreationDate
        // with offset, no OffsetTime* tags) are flagged as ambiguous. We cannot
        // determine if the timestamp is UTC (modern cameras) or local time (old cameras).
        // The user sees [W] and can use --timezone for manual correction.
        //
        // Exception: HEIC/HEIF images are ISO BMFF containers (detected as QuickTime)
        // but store EXIF dates in local time like JPEG. If the file has a real EXIF
        // DateTimeOriginal tag (from the EXIF document, not from QuickTime atoms),
        // the timezone is NOT ambiguous.
        $isAmbiguousTimezone = $isQuickTimeContainer
            && (!$hasExifDateTimeOriginal)
            && (!$temporal->tz instanceof DateTimeZone)
            && ($temporal->offsetTimeOriginal === null)
            && ($temporal->offsetTimeDigitized === null)
            && ($temporal->offsetTime === null);

        return [$dateTime, $isFallback, $isAmbiguousTimezone];
    }

    /**
     * Extracts a unique content identifier for grouping (e.g. Live Photo ID).
     *
     * @param Metadata $metadata The metadata container.
     *
     * @return string|null The identifier, or null if none is present.
     */
    private function extractContentIdentifier(Metadata $metadata): ?string
    {
        $contentIdentifier = $metadata->structured()->makerNotesApple?->identity->contentIdentifier
            ?? $metadata->quickTime?->contentIdentifier();

        if ($contentIdentifier === null) {
            return null;
        }

        $trimmed = trim($contentIdentifier);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Extracts the video duration in seconds.
     *
     * @param Metadata $metadata The metadata container.
     *
     * @return float|null Duration in seconds, or null if not available.
     */
    private function extractVideoDurationSeconds(Metadata $metadata): ?float
    {
        if (!$metadata->quickTime instanceof QuickTimeMeta) {
            return null;
        }

        $duration = $metadata->quickTime->floatValue('com.apple.quicktime.duration');

        if ($duration === null) {
            return null;
        }

        $timeScale = $metadata->quickTime->intValue('TimeScale');

        if (($timeScale !== null) && ($timeScale > 1)) {
            return $duration / $timeScale;
        }

        return $duration;
    }

    /**
     * Checks for QuickTime atoms that indicate a Live Photo video part.
     *
     * @param Metadata $metadata The metadata container.
     *
     * @return bool True if Live Photo markers are found.
     */
    private function hasQuickTimeLivePhotoMarker(Metadata $metadata): bool
    {
        if (!$metadata->quickTime instanceof QuickTimeMeta) {
            return false;
        }

        if ($metadata->quickTime->boolValue(QuickTimeMeta::STILL_IMAGE_TIME_KEY) ?? false) {
            return true;
        }

        return $metadata->quickTime->boolValue(QuickTimeMeta::HAS_LIVE_PHOTO_INFO_KEY) ?? false;
    }

    /**
     * Normalizes a string value by trimming and converting empty strings to null.
     *
     * @param string|null $value The value to normalize.
     *
     * @return string|null The normalized value.
     */
    private function normalizeNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
