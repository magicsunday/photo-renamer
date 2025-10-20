<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function array_values;
use function is_string;

/**
 * Represents the values captured for a single regex group.
 */
final class RegexMatchGroup
{
    /**
     * @param list<string> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromSingleValue(string $value): self
    {
        return new self([$value]);
    }

    /**
     * @param array<int|string, string> $values
     */
    public static function fromList(array $values): self
    {
        $filtered = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $filtered[] = $value;
            }
        }

        return new self(array_values($filtered));
    }

    public function first(): ?string
    {
        return $this->values[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
