<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

final class ExifStringValue extends AbstractExifValue
{
    public function __construct(private readonly string $value)
    {
    }

    public function asString(): ?string
    {
        return $this->value;
    }
}
