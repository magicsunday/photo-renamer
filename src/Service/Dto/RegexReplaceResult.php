<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

/**
 * Immutable wrapper containing the output of a regex replacement operation.
 */
final class RegexReplaceResult implements RegexResultInterface
{
    public function __construct(private readonly string $result)
    {
    }

    public function result(): string
    {
        return $this->result;
    }
}
