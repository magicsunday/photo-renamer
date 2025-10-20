<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function array_key_exists;
use function is_string;

/**
 * Immutable value object encapsulating raw EXIF metadata.
 */
final class ExifRawMetadata
{
    /**
     * @param array<string|int, mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function getString(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
