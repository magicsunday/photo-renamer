<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

/**
 * Value object representing a QuickTime metadata value entry.
 */
final class QuickTimeValue
{
    /**
     * @param int    $index The one-based atom index that ties the value to a key.
     * @param string $value The decoded string payload stored in the metadata item.
     */
    public function __construct(
        private readonly int $index,
        private readonly string $value,
    ) {
    }

    /**
     * Returns the index that links the value back to its QuickTime key.
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Returns the string content extracted from the QuickTime metadata item.
     */
    public function getValue(): string
    {
        return $this->value;
    }
}
