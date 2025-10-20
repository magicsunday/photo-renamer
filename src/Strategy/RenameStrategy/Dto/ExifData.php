<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

/**
 * Value object containing EXIF metadata relevant for renaming.
 */
final class ExifData
{
    public function __construct(
        private readonly string $dateTimeOriginal,
        private readonly ?string $subSecTimeOriginal,
        private readonly ?ContentIdentifier $contentIdentifier,
    ) {
    }

    public function getDateTimeOriginal(): string
    {
        return $this->dateTimeOriginal;
    }

    public function getSubSecTimeOriginal(): ?string
    {
        return $this->subSecTimeOriginal;
    }

    public function getContentIdentifier(): ?ContentIdentifier
    {
        return $this->contentIdentifier;
    }
}
