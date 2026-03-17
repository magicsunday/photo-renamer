<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Regex;

/**
 * Immutable wrapper around the result of a single regular expression match.
 */
final readonly class RegexMatchResult
{
    /**
     * Creates a value object from the result of a single preg_match call.
     *
     * @param array<int|string, string> $matches match data returned by the regex engine
     */
    public function __construct(private array $matches)
    {
    }

    /**
     * Returns whether the pattern matched the subject.
     */
    public function isMatch(): bool
    {
        return $this->matches !== [];
    }

    /**
     * Returns the captured match data.
     *
     * @return array<int|string, string> match data returned by the regex engine
     */
    public function matches(): array
    {
        return $this->matches;
    }
}
