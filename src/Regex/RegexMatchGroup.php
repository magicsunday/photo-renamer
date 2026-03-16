<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Regex;

use function array_values;

/**
 * Represents the values captured for a single regex group.
 */
final readonly class RegexMatchGroup
{
    /**
     * @param list<string> $values
     */
    private function __construct(private array $values)
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
        return new self(array_values($values));
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
