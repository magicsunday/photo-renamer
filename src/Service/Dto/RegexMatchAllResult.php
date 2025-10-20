<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

/**
 * Immutable wrapper around the result of a preg_match_all call.
 */
final class RegexMatchAllResult implements RegexResultInterface
{
    /**
     * Creates a value object from the raw matches array returned by preg_match_all.
     *
     * @param array<int, array<int|string, string>> $matches Nested match structure from the regex engine.
     */
    public function __construct(private readonly array $matches)
    {
    }

    /**
     * Returns the raw match data.
     *
     * @return array<int, array<int|string, string>> Nested match structure from the regex engine.
     */
    public function matches(): array
    {
        return $this->matches;
    }
}
