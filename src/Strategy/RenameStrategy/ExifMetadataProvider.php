<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use DateTimeInterface;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ContentIdentifier;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifData;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\MetadataEntryCollection;
use MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime\QuickTimeContentIdentifierExtractor;
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

    public function __construct(
        private readonly SafeExifReader $safeExifReader,
        private readonly QuickTimeContentIdentifierExtractor $quickTimeContentIdentifierExtractor,
    ) {
        $this->exifDataCache = new SplObjectStorage();
        $this->contentIdentifierCache = new SplObjectStorage();
    }

    public function getExifData(SplFileInfo $splFileInfo): ?ExifData
    {
        if (!$this->exifDataCache->offsetExists($splFileInfo)) {
            try {
                $this->exifDataCache[$splFileInfo] = $this->createExifData($splFileInfo);
            } catch (ExifMetadataReadException $exception) {
                throw new TargetFilenameException($exception->getMessage(), previous: $exception);
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
        $metadataResult = $this->safeExifReader->read($splFileInfo);

        $contentIdentifier = null;
        $metadata = $metadataResult->metadata();

        if ($metadata !== null) {
            $metadataEntries = MetadataEntryCollection::fromMetadata($metadata);
            $contentIdentifier = $this->extractContentIdentifierFromMetadata($metadataEntries);
        }

        if ($contentIdentifier === null) {
            $contentIdentifier = $this->quickTimeContentIdentifierExtractor->extractContentIdentifier($splFileInfo);
        }

        $dateTimeOriginal = null;
        $subSecTimeOriginal = null;

        if ($metadata !== null) {
            $dateTimeOriginal = $metadata->getString('DateTimeOriginal');

            if ($dateTimeOriginal !== null && $dateTimeOriginal !== '') {
                $subSecTimeOriginal = $metadata->getString('SubSecTimeOriginal');

                if ($subSecTimeOriginal === null || $subSecTimeOriginal === '') {
                    $subSecTimeOriginal = null;
                }
            } else {
                $dateTimeOriginal = null;
            }
        }

        if ($dateTimeOriginal === null) {
            $quickTimeDate = $this->quickTimeContentIdentifierExtractor->extractCreationDate($splFileInfo);

            if ($quickTimeDate !== null) {
                [$dateTimeOriginal, $subSecTimeOriginal] = $this->normaliseQuickTimeTimestamp($quickTimeDate);
            }
        }

        $this->contentIdentifierCache[$splFileInfo] = $contentIdentifier;

        if ($dateTimeOriginal === null) {
            return null;
        }

        return new ExifData($dateTimeOriginal, $subSecTimeOriginal, $contentIdentifier);
    }

    private function extractContentIdentifierFromMetadata(MetadataEntryCollection $entries): ?ContentIdentifier
    {
        $match = $entries->findContentIdentifier();

        if ($match === null) {
            return null;
        }

        return new ContentIdentifier($match->getValue());
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function normaliseQuickTimeTimestamp(DateTimeInterface $creationDate): array
    {
        $dateTimeOriginal = $creationDate->format('Y:m:d H:i:s');
        $microseconds = (int) $creationDate->format('u');

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

