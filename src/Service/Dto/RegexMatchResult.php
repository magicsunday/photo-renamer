<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

/**
 * Immutable wrapper around the result of a single regular expression match.
 */
final class RegexMatchResult implements RegexResultInterface
{
    /**
     * Creates a value object from the result of a single preg_match call.
     *
     * @param array<int|string, string> $matches Match data returned by the regex engine.
     */
    public function __construct(private readonly array $matches)
    {
    }

    /**
     * Returns the captured match data.
     *
     * @return array<int|string, string> Match data returned by the regex engine.
     */
    public function matches(): array
    {
        return $this->matches;
    }
}
