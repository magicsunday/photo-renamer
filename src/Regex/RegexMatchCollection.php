<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Regex;

use function is_int;

/**
 * Immutable collection of RegexMatchGroup instances, built from either a single
 * preg_match() or a preg_match_all() result. Provides indexed access to capture
 * groups by their numeric position.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RegexMatchCollection
{
    /**
     * @param array<int, RegexMatchGroup> $groups Capture groups indexed by group number
     */
    private function __construct(private array $groups)
    {
    }

    /**
     * Creates a collection from a single preg_match() result, wrapping each
     * captured value into a single-element RegexMatchGroup.
     *
     * @param RegexMatchResult $result Result from SafeRegex::match()
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
     * Creates a collection from a preg_match_all() result, wrapping each
     * group's match list into a multi-element RegexMatchGroup.
     *
     * @param RegexMatchAllResult $result Result from SafeRegex::matchAll()
     */
    public static function fromMatchAll(RegexMatchAllResult $result): self
    {
        return new self(
            array_map(
                static fn (array $groupMatches): RegexMatchGroup => RegexMatchGroup::fromList($groupMatches),
                $result->matches(),
            ),
        );
    }

    /**
     * Returns the number of capture groups in the collection (including group 0).
     */
    public function count(): int
    {
        return count($this->groups);
    }

    /**
     * Checks whether a non-empty capture group exists at the given index.
     *
     * @param int $index Zero-based group index
     */
    public function hasGroup(int $index): bool
    {
        return isset($this->groups[$index]) && !$this->groups[$index]->isEmpty();
    }

    /**
     * Returns the capture group at the given index, or null when it does not exist.
     *
     * @param int $index Zero-based group index
     */
    public function group(int $index): ?RegexMatchGroup
    {
        return $this->groups[$index] ?? null;
    }
}
