<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Regex;

use function array_key_last;
use function is_int;

/**
 * Immutable collection representing regex match groups.
 */
final readonly class RegexMatchCollection
{
    /**
     * @param array<int, RegexMatchGroup> $groups
     */
    private function __construct(private array $groups)
    {
    }

    /**
     * Builds a collection from a single `preg_match` call.
     */
    public static function fromMatch(RegexMatchResult $result): self
    {
        $groups = [];

        foreach ($result->matches() as $key => $value) {
            if (!is_int($key)) {
                continue;
            }

            $groups[$key] = RegexMatchGroup::fromSingleValue($value);
        }

        return new self($groups);
    }

    /**
     * Builds a collection from a `preg_match_all` call.
     */
    public static function fromMatchAll(RegexMatchAllResult $result): self
    {
        $groups = [];

        foreach ($result->matches() as $key => $groupMatches) {
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
