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

    /**
     * Stores a target file for the given content identifier if it is not already tracked.
     *
     * @param string $contentIdentifier Identifier shared by all assets of a Live Photo.
     * @param SplFileInfo $target Target file associated with the identifier.
     *
     * @return void
     */
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

    /**
     * Checks whether a target has been stored for the given content identifier.
     *
     * @param string $contentIdentifier Identifier to look up.
     *
     * @return bool True when a target has been remembered.
     */
    public function has(string $contentIdentifier): bool
    {
        return array_key_exists($contentIdentifier, $this->targets);
    }

    /**
     * Retrieves the stored target for the given content identifier.
     *
     * @param string $contentIdentifier Identifier whose target should be returned.
     *
     * @return SplFileInfo Target previously associated with the identifier.
     */
    public function get(string $contentIdentifier): SplFileInfo
    {
        return $this->targets[$contentIdentifier];
    }
}
