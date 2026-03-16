<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
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
    /** @var array<string, LivePhotoContentIdentifierTarget> */
    private array $targets = [];

    /**
     * Stores a target file for the given content identifier if it is not already tracked.
     *
     * @param string      $contentIdentifier   identifier shared by all assets of a Live Photo
     * @param SplFileInfo $target              target file associated with the identifier
     * @param string      $duplicateIdentifier collection key referencing the FileDuplicate entry
     */
    public function remember(string $contentIdentifier, SplFileInfo $target, string $duplicateIdentifier): void
    {
        if ($contentIdentifier === '') {
            return;
        }

        if (array_key_exists($contentIdentifier, $this->targets)) {
            return;
        }

        $this->targets[$contentIdentifier] = new LivePhotoContentIdentifierTarget($target, $duplicateIdentifier);
    }

    /**
     * Checks whether a target has been stored for the given content identifier.
     *
     * @param string $contentIdentifier identifier to look up
     *
     * @return bool true when a target has been remembered
     */
    public function has(string $contentIdentifier): bool
    {
        return array_key_exists($contentIdentifier, $this->targets);
    }

    /**
     * Retrieves the stored target for the given content identifier.
     *
     * @param string $contentIdentifier identifier whose target should be returned
     *
     * @return LivePhotoContentIdentifierTarget target previously associated with the identifier
     */
    public function get(string $contentIdentifier): LivePhotoContentIdentifierTarget
    {
        return $this->targets[$contentIdentifier];
    }
}
