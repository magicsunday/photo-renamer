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
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use SplFileInfo;
use SplObjectStorage;

use function sprintf;

/**
 * Caching facade over the MetadataExtractor that converts raw TemporalMetadata
 * into ExifData value objects. Maintains per-file SplObjectStorage caches for
 * both ExifData and ContentIdentifier, so each file is extracted at most once
 * even when queried from multiple pipeline stages.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class ExifMetadataProvider
{
    /**
     * Per-file cache of extracted EXIF data (null when the file lacks usable metadata).
     *
     * @var SplObjectStorage<SplFileInfo, ExifData|null>
     */
    private SplObjectStorage $exifDataCache;

    /**
     * Per-file cache of Live Photo content identifiers, populated as a side-effect
     * of EXIF extraction so companion detection can query it independently.
     *
     * @var SplObjectStorage<SplFileInfo, ContentIdentifier|null>
     */
    private SplObjectStorage $contentIdentifierCache;

    public function __construct(private readonly MetadataExtractorInterface $metadataExtractor)
    {
        $this->exifDataCache          = new SplObjectStorage();
        $this->contentIdentifierCache = new SplObjectStorage();
    }

    /**
     * Returns the ExifData for the given file, extracting and caching it on first access.
     * Returns null when the file contains no usable EXIF date information.
     *
     * @param SplFileInfo $splFileInfo File to extract metadata from
     *
     * @return ExifData|null Extracted EXIF data, or null when unavailable
     *
     * @throws TargetFilenameException When the underlying metadata reader fails
     */
    public function getExifData(SplFileInfo $splFileInfo): ?ExifData
    {
        if (!$this->exifDataCache->offsetExists($splFileInfo)) {
            try {
                $this->exifDataCache[$splFileInfo] = $this->createExifData($splFileInfo);
            } catch (ExifMetadataReadException $exception) {
                throw new TargetFilenameException(sprintf('Unable to read image metadata from "%s": %s', $splFileInfo->getPathname(), $exception->getMessage()), $exception->getCode(), previous: $exception);
            }
        }

        $exifData = $this->exifDataCache[$splFileInfo];

        return $exifData instanceof ExifData ? $exifData : null;
    }

    /**
     * Returns the Live Photo content identifier for the given file. Triggers EXIF
     * extraction if not yet cached, since the content identifier is populated as a
     * side-effect of createExifData().
     *
     * @param SplFileInfo $splFileInfo File to query
     *
     * @return ContentIdentifier|null The content identifier, or null when the file
     *                                is not part of an Apple Live Photo pair
     */
    public function getContentIdentifier(SplFileInfo $splFileInfo): ?ContentIdentifier
    {
        $this->getExifData($splFileInfo);

        if (!$this->contentIdentifierCache->offsetExists($splFileInfo)) {
            return null;
        }

        $contentIdentifier = $this->contentIdentifierCache[$splFileInfo];

        return $contentIdentifier instanceof ContentIdentifier ? $contentIdentifier : null;
    }

    /**
     * Extracts temporal metadata and Live Photo content identifier from the file,
     * populating the content identifier cache as a side-effect.
     *
     * @param SplFileInfo $splFileInfo File to extract metadata from
     *
     * @return ExifData|null Normalized EXIF data, or null when no capture date is available
     *
     * @throws ExifMetadataReadException When the metadata reader encounters an error
     */
    private function createExifData(SplFileInfo $splFileInfo): ?ExifData
    {
        $temporalMetadata  = $this->metadataExtractor->extractTemporalMetadata($splFileInfo);
        $contentIdentifier = $this->extractContentIdentifier($temporalMetadata);

        $this->contentIdentifierCache[$splFileInfo] = $contentIdentifier;

        if (!$temporalMetadata instanceof TemporalMetadata) {
            return null;
        }

        $captureDateTime = $temporalMetadata->getCaptureDateTime();

        if (!$captureDateTime instanceof DateTimeInterface) {
            return null;
        }

        [$dateTimeOriginal, $subSecTimeOriginal] = $this->normaliseCaptureTimestamp($captureDateTime);

        return new ExifData($dateTimeOriginal, $subSecTimeOriginal, $contentIdentifier);
    }

    /**
     * Wraps the raw Live Photo ID string from TemporalMetadata into a
     * ContentIdentifier value object, normalizing case and whitespace.
     *
     * @param TemporalMetadata|null $temporalMetadata Raw metadata to extract from
     *
     * @return ContentIdentifier|null Normalized identifier, or null when not present
     */
    private function extractContentIdentifier(?TemporalMetadata $temporalMetadata): ?ContentIdentifier
    {
        if (!$temporalMetadata instanceof TemporalMetadata) {
            return null;
        }

        $livePhotoId = $temporalMetadata->getLivePhotoId();

        if ($livePhotoId === null || $livePhotoId === '') {
            return null;
        }

        return new ContentIdentifier($livePhotoId);
    }

    /**
     * Splits a DateTimeInterface into the "Y:m:d H:i:s" date string and an optional
     * sub-second string. Sub-second precision is expressed as 3 digits (milliseconds)
     * when the microsecond value is evenly divisible by 1000, or 6 digits otherwise.
     * Returns null for sub-seconds when the capture time has no fractional component.
     *
     * @param DateTimeInterface $captureDateTime Capture timestamp with potential microsecond precision
     *
     * @return array{0: string, 1: ?string} Tuple of [dateTimeOriginal, subSecTimeOriginal]
     */
    private function normaliseCaptureTimestamp(DateTimeInterface $captureDateTime): array
    {
        $dateTimeOriginal = $captureDateTime->format('Y:m:d H:i:s');
        $microseconds     = (int) $captureDateTime->format('u');

        if ($microseconds === 0) {
            return [$dateTimeOriginal, null];
        }

        if ($microseconds % 1000 === 0) {
            $milliseconds = (int) ($microseconds / 1000);

            return [$dateTimeOriginal, sprintf('%03d', $milliseconds)];
        }

        return [$dateTimeOriginal, sprintf('%06d', $microseconds)];
    }
}
