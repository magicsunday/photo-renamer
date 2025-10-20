<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function stripos;

/**
 * Immutable collection containing QuickTime metadata keys and values.
 */
final class QuickTimeMetadata
{
    /**
     * @param array<int, QuickTimeKey>   $keys
     * @param array<int, QuickTimeValue> $values
     */
    private function __construct(
        private readonly array $keys,
        private readonly array $values,
    ) {
    }

    /**
     * Creates an empty metadata container without keys or values.
     *
     * @return self The empty metadata instance.
     */
    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Returns a new metadata instance with the provided key added or replaced.
     *
     * @param QuickTimeKey $key The key entry retrieved from the keys atom.
     *
     * @return self The updated metadata including the key.
     */
    public function withKey(QuickTimeKey $key): self
    {
        $keys = $this->keys;
        $keys[$key->getIndex()] = $key;

        return new self($keys, $this->values);
    }

    /**
     * Returns a new metadata instance with the provided value added or replaced.
     *
     * @param QuickTimeValue $value The metadata value associated with a key index.
     *
     * @return self The updated metadata including the value.
     */
    public function withValue(QuickTimeValue $value): self
    {
        $values = $this->values;
        $values[$value->getIndex()] = $value;

        return new self($this->keys, $values);
    }

    /**
     * Retrieves the key stored at the given index.
     *
     * @param int $index The atom index referencing the key.
     *
     * @return QuickTimeKey|null The matching key or null when unknown.
     */
    public function getKey(int $index): ?QuickTimeKey
    {
        return $this->keys[$index] ?? null;
    }

    /**
     * Retrieves the value stored at the given index.
     *
     * @param int $index The atom index referencing the value.
     *
     * @return QuickTimeValue|null The matching value or null when missing.
     */
    public function getValue(int $index): ?QuickTimeValue
    {
        return $this->values[$index] ?? null;
    }

    /**
     * Searches the metadata for a value whose key name contains the provided fragment.
     *
     * @param string $fragment The case-insensitive key fragment to look for.
     *
     * @return QuickTimeValue|null The associated value or null if no key matches.
     */
    public function findValueByKeyFragment(string $fragment): ?QuickTimeValue
    {
        foreach ($this->keys as $index => $key) {
            if (stripos($key->getName(), $fragment) !== false) {
                return $this->values[$index] ?? null;
            }
        }

        return null;
    }
}
