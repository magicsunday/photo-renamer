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
 * Immutable pair associating a canonical target file with the duplicate group
 * identifier it belongs to. Stored in content-identifier and basename lookup
 * maps to enable Live Photo companion resolution during the pairing phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LivePhotoContentIdentifierTarget
{
    /**
     * @param SplFileInfo $target              Canonical target file defining the group's base name
     * @param string      $duplicateIdentifier Key referencing the FileDuplicate entry in the collection
     */
    public function __construct(
        private SplFileInfo $target,
        private string $duplicateIdentifier,
    ) {
    }

    /**
     * Returns the canonical target file whose basename companions should inherit.
     */
    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    /**
     * Returns the FileDuplicateCollection key that identifies the group
     * this target belongs to.
     */
    public function getDuplicateIdentifier(): string
    {
        return $this->duplicateIdentifier;
    }
}
