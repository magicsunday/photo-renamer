<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

final class MetadataEntry
{
    public function __construct(
        private readonly string $path,
        private readonly string $value,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
