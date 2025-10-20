<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

final class ExifArrayValue extends AbstractExifValue
{
    public function __construct(private readonly ExifMetadataCollection $collection)
    {
    }

    public function asArray(): ?ExifMetadataCollection
    {
        return $this->collection;
    }
}
