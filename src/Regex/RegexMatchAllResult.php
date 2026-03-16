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
 * Immutable wrapper around the result of a preg_match_all call.
 */
final readonly class RegexMatchAllResult
{
    /**
     * Creates a value object from the raw matches array returned by preg_match_all.
     *
     * @param array<int, array<int|string, string>> $matches nested match structure from the regex engine
     */
    public function __construct(private array $matches)
    {
    }

    /**
     * Returns the raw match data.
     *
     * @return array<int, array<int|string, string>> nested match structure from the regex engine
     */
    public function matches(): array
    {
        return $this->matches;
    }
}
