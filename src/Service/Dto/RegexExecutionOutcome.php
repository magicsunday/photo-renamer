<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use LogicException;

/**
 * Represents the outcome of an executed regular expression operation.
 */
final class RegexExecutionOutcome
{
    /**
     * Initializes the outcome with a success flag and optional result.
     *
     * @param bool $successful Indicates whether the regex operation succeeded.
     * @param RegexResultInterface|null $result Result returned when the operation was successful.
     */
    private function __construct(private readonly bool $successful, private readonly ?RegexResultInterface $result)
    {
    }

    /**
     * Creates a successful outcome containing a result.
     *
     * @param RegexResultInterface $result Result produced by the regex execution.
     *
     * @return self Outcome representing a successful regex call.
     */
    public static function success(RegexResultInterface $result): self
    {
        return new self(true, $result);
    }

    /**
     * Creates a failed outcome without a result.
     *
     * @return self Outcome representing an unsuccessful regex call.
     */
    public static function failure(): self
    {
        return new self(false, null);
    }

    /**
     * Indicates whether the regex execution completed successfully.
     *
     * @return bool True when the execution succeeded.
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Returns the result of a successful regex execution.
     *
     * @throws LogicException When the execution was unsuccessful.
     *
     * @return RegexResultInterface Result produced by the regex execution.
     */
    public function result(): RegexResultInterface
    {
        if ($this->result === null) {
            throw new LogicException('No result available for an unsuccessful regex operation.');
        }

        return $this->result;
    }
}
