<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

/**
 * Value object representing a QuickTime metadata key entry.
 */
final class QuickTimeKey
{
    /**
     * @param int    $index The one-based atom index of the key entry.
     * @param string $name  The identifier assigned to the metadata key.
     */
    public function __construct(
        private readonly int $index,
        private readonly string $name,
    ) {
    }

    /**
     * Returns the position of the key within the QuickTime keys atom.
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Returns the descriptive name associated with the metadata key.
     */
    public function getName(): string
    {
        return $this->name;
    }
}
