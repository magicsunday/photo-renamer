<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

final class ExifIntegerValue extends AbstractExifValue
{
    public function __construct(private readonly int $value)
    {
    }

    public function asInt(): ?int
    {
        return $this->value;
    }
}
