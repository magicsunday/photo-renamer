<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

/**
 * Immutable value object encapsulating raw EXIF metadata.
 */
final class ExifRawMetadata
{
    private function __construct(private readonly ExifMetadataCollection $data)
    {
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ExifMetadataCollection::fromArray($data));
    }

    public function hasKey(string $key): bool
    {
        return $this->data->has($key);
    }

    public function get(string $key): ?ExifValue
    {
        return $this->data->get($key);
    }

    public function getString(string $key): ?string
    {
        return $this->get($key)?->asString();
    }

    public function getInt(string $key): ?int
    {
        return $this->get($key)?->asInt();
    }

    public function getFloat(string $key): ?float
    {
        return $this->get($key)?->asFloat();
    }

    public function getBool(string $key): ?bool
    {
        return $this->get($key)?->asBool();
    }

    public function values(): ExifMetadataCollection
    {
        return $this->data;
    }
}
