<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
        private readonly ?string $contentIdentifier,
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

    public function getContentIdentifier(): ?string
    {
        return $this->contentIdentifier;
    }
}
