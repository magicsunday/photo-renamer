<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

/**
 * Immutable wrapper around the result of a preg_match_all call.
 */
final class RegexMatchAllResult
{
    /**
     * @param array<int, array<int|string, string>> $matches
     */
    public function __construct(private readonly array $matches)
    {
    }

    /**
     * @return array<int, array<int|string, string>>
     */
    public function matches(): array
    {
        return $this->matches;
    }
}
