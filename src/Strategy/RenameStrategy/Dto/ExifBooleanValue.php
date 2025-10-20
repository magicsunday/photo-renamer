<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

final class ExifBooleanValue extends AbstractExifValue
{
    public function __construct(private readonly bool $value)
    {
    }

    public function asBool(): ?bool
    {
        return $this->value;
    }
}
