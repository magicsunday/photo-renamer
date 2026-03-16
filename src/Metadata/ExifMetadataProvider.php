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

final class ExifMetadataProvider
{
    /**
     * @var SplObjectStorage<SplFileInfo, ExifData|null>
     */
    private SplObjectStorage $exifDataCache;

    /**
     * @var SplObjectStorage<SplFileInfo, ContentIdentifier|null>
     */
    private SplObjectStorage $contentIdentifierCache;

    public function __construct(private readonly MetadataExtractorInterface $metadataExtractor)
    {
        $this->exifDataCache          = new SplObjectStorage();
        $this->contentIdentifierCache = new SplObjectStorage();
    }

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

    public function getContentIdentifier(SplFileInfo $splFileInfo): ?ContentIdentifier
    {
        $this->getExifData($splFileInfo);

        if (!$this->contentIdentifierCache->offsetExists($splFileInfo)) {
            return null;
        }

        $contentIdentifier = $this->contentIdentifierCache[$splFileInfo];

        return $contentIdentifier instanceof ContentIdentifier ? $contentIdentifier : null;
    }

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
     * @return array{0: string, 1: ?string}
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
