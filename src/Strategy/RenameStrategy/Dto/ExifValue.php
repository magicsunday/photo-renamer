<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

interface ExifValue
{
    public function asString(): ?string;

    public function asInt(): ?int;

    public function asFloat(): ?float;

    public function asBool(): ?bool;

    public function asArray(): ?ExifMetadataCollection;
}
