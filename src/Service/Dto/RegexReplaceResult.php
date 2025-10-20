<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

/**
 * Immutable wrapper containing the output of a regex replacement operation.
 */
final class RegexReplaceResult implements RegexResultInterface
{
    /**
     * Creates a value object from the output of preg_replace.
     *
     * @param string $result Replacement result returned by the regex engine.
     */
    public function __construct(private readonly string $result)
    {
    }

    /**
     * Returns the replacement result.
     *
     * @return string Replacement result returned by the regex engine.
     */
    public function result(): string
    {
        return $this->result;
    }
}
