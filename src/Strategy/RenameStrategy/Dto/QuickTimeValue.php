<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

/**
 * Value object representing a QuickTime metadata value entry.
 */
final class QuickTimeValue
{
    public function __construct(
        private readonly int $index,
        private readonly string $value,
    ) {
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
