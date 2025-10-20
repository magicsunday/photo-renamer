<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use SplFileInfo;

use function array_key_exists;

/**
 * Index of Live Photo targets by content identifier.
 */
final class LivePhotoContentIdentifierTargetMap
{
    /** @var array<string, SplFileInfo> */
    private array $targets = [];

    public function remember(string $contentIdentifier, SplFileInfo $target): void
    {
        if ($contentIdentifier === '') {
            return;
        }

        if (array_key_exists($contentIdentifier, $this->targets)) {
            return;
        }

        $this->targets[$contentIdentifier] = $target;
    }

    public function has(string $contentIdentifier): bool
    {
        return array_key_exists($contentIdentifier, $this->targets);
    }

    public function get(string $contentIdentifier): SplFileInfo
    {
        return $this->targets[$contentIdentifier];
    }
}
