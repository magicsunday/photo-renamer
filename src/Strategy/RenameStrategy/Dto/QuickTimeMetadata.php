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

    public static function empty(): self
    {
        return new self([], []);
    }

    public function withKey(QuickTimeKey $key): self
    {
        $keys = $this->keys;
        $keys[$key->getIndex()] = $key;

        return new self($keys, $this->values);
    }

    public function withValue(QuickTimeValue $value): self
    {
        $values = $this->values;
        $values[$value->getIndex()] = $value;

        return new self($this->keys, $values);
    }

    public function getKey(int $index): ?QuickTimeKey
    {
        return $this->keys[$index] ?? null;
    }

    public function getValue(int $index): ?QuickTimeValue
    {
        return $this->values[$index] ?? null;
    }

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
