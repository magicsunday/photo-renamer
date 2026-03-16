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
 * Immutable list of string values captured for a single regex group.
 * Contains one element for preg_match() results or multiple elements
 * for preg_match_all() results.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RegexMatchGroup
{
    /**
     * @param list<string> $values Captured values for this group
     */
    private function __construct(private array $values)
    {
    }

    /**
     * Creates a group containing a single captured value (from preg_match()).
     *
     * @param string $value The captured string
     */
    public static function fromSingleValue(string $value): self
    {
        return new self([$value]);
    }

    /**
     * Creates a group from a list of captured values (from preg_match_all()).
     * Named keys are discarded; values are re-indexed to a zero-based list.
     *
     * @param array<int|string, string> $values Captured strings for this group
     */
    public static function fromList(array $values): self
    {
        return new self(array_values($values));
    }

    /**
     * Returns the first captured value, or null when the group is empty.
     */
    public function first(): ?string
    {
        return $this->values[0] ?? null;
    }

    /**
     * Returns all captured values as a zero-based list.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Checks whether this group captured no values.
     */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
