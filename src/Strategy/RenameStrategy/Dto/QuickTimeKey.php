<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

/**
 * Value object representing a QuickTime metadata key entry.
 */
final class QuickTimeKey
{
    public function __construct(
        private readonly int $index,
        private readonly string $name,
    ) {
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
