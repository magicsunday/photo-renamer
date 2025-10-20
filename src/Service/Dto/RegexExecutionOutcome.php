<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use LogicException;

/**
 * Represents the outcome of an executed regular expression operation.
 */
final class RegexExecutionOutcome
{
    private function __construct(private readonly bool $successful, private readonly ?RegexResultInterface $result)
    {
    }

    public static function success(RegexResultInterface $result): self
    {
        return new self(true, $result);
    }

    public static function failure(): self
    {
        return new self(false, null);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function result(): RegexResultInterface
    {
        if ($this->result === null) {
            throw new LogicException('No result available for an unsuccessful regex operation.');
        }

        return $this->result;
    }
}
