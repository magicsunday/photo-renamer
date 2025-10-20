<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function array_key_last;
use function is_array;
use function is_int;
use function is_string;

/**
 * Immutable collection representing regex match groups.
 */
final class RegexMatchCollection
{
    /**
     * @param array<int, RegexMatchGroup> $groups
     */
    private function __construct(private readonly array $groups)
    {
    }

    /**
     * Builds a collection from a single `preg_match` call.
     *
     * @param array<int|string, string> $matches
     */
    public static function fromMatch(array $matches): self
    {
        $groups = [];

        foreach ($matches as $key => $value) {
            if (!is_int($key) || !is_string($value)) {
                continue;
            }

            $groups[$key] = RegexMatchGroup::fromSingleValue($value);
        }

        return new self($groups);
    }

    /**
     * Builds a collection from a `preg_match_all` call.
     *
     * @param array<int, array<int|string, string>> $matches
     */
    public static function fromMatchAll(array $matches): self
    {
        $groups = [];

        foreach ($matches as $key => $groupMatches) {
            if (!is_int($key) || !is_array($groupMatches)) {
                continue;
            }

            $groups[$key] = RegexMatchGroup::fromList($groupMatches);
        }

        return new self($groups);
    }

    public function count(): int
    {
        return count($this->groups);
    }

    public function hasGroup(int $index): bool
    {
        return isset($this->groups[$index]) && !$this->groups[$index]->isEmpty();
    }

    public function group(int $index): ?RegexMatchGroup
    {
        return $this->groups[$index] ?? null;
    }

    public function lastGroup(): ?RegexMatchGroup
    {
        $lastKey = array_key_last($this->groups);

        if ($lastKey === null) {
            return null;
        }

        return $this->groups[$lastKey];
    }
}
