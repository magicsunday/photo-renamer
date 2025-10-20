<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

abstract class AbstractExifValue implements ExifValue
{
    public function asString(): ?string
    {
        return null;
    }

    public function asInt(): ?int
    {
        return null;
    }

    public function asFloat(): ?float
    {
        return null;
    }

    public function asBool(): ?bool
    {
        return null;
    }

    public function asArray(): ?ExifMetadataCollection
    {
        return null;
    }
}
