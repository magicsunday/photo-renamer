<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

final class ExifFloatValue extends AbstractExifValue
{
    public function __construct(private readonly float $value)
    {
    }

    public function asFloat(): ?float
    {
        return $this->value;
    }
}
