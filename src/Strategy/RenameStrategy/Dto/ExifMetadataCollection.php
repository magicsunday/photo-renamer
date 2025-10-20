<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use ArrayIterator;
use Traversable;

use function array_key_exists;

/**
 * @implements \IteratorAggregate<int|string, ExifValue>
 */
final class ExifMetadataCollection implements \IteratorAggregate
{
    /**
     * @param array<int|string, ExifValue> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $values = [];

        foreach ($data as $key => $value) {
            $values[$key] = ExifValueFactory::fromNative($value);
        }

        return new self($values);
    }

    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(int|string $key): ?ExifValue
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @return array<int|string, ExifValue>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }
}
