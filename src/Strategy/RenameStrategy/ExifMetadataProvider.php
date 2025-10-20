<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ContentIdentifier;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifData;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\MetadataEntryCollection;
use MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime\QuickTimeContentIdentifierExtractor;
use SplFileInfo;
use SplObjectStorage;

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

        $this->contentIdentifierCache[$splFileInfo] = $contentIdentifier;

        if ($metadata === null) {
            return null;
        }

        $dateTimeOriginal = $metadata->getString('DateTimeOriginal');

        if ($dateTimeOriginal === null || $dateTimeOriginal === '') {
            return null;
        }

        $subSecTimeOriginal = $metadata->getString('SubSecTimeOriginal');

        if ($subSecTimeOriginal === null || $subSecTimeOriginal === '') {
            $subSecTimeOriginal = null;
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
}

