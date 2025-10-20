<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use SplFileInfo;

/**
 * Descriptor pairing a remembered Live Photo target with its duplicate key.
 */
final class LivePhotoContentIdentifierTarget
{
    public function __construct(
        private readonly SplFileInfo $target,
        private readonly string $duplicateIdentifier,
    ) {
    }

    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    public function getDuplicateIdentifier(): string
    {
        return $this->duplicateIdentifier;
    }
}
