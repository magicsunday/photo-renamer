<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use SplFileInfo;

/**
 * Descriptor pairing a remembered Live Photo target with its duplicate key.
 */
final readonly class LivePhotoContentIdentifierTarget
{
    public function __construct(
        private SplFileInfo $target,
        private string $duplicateIdentifier,
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
