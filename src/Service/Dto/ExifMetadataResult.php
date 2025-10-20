<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;

final class ExifMetadataResult
{
    private function __construct(private readonly ?ExifRawMetadata $metadata)
    {
    }

    public static function withoutMetadata(): self
    {
        return new self(null);
    }

    public static function withMetadata(ExifRawMetadata $metadata): self
    {
        return new self($metadata);
    }

    public function hasMetadata(): bool
    {
        return $this->metadata !== null;
    }

    public function metadata(): ?ExifRawMetadata
    {
        return $this->metadata;
    }
}
