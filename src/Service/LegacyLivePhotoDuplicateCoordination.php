<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Rename;

/**
 * Carries the result of coordinating one legacy Live Photo duplicate group.
 *
 * The legacy duplicate pipeline needs two pieces of derived state from the
 * companion-detection step: the companion rename itself and, when pairing
 * succeeded, the normalized still/companion pathname pair used by later flag
 * propagation. Keeping both in a small immutable result object avoids leaking
 * tuple conventions back into the main grouping loop.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyLivePhotoDuplicateCoordination
{
    /**
     * @param Rename|null              $companionRename Detected companion rename, or null when no pair could be established.
     * @param LegacyLivePhotoPair|null $livePhotoPair   Normalized still/companion pair used for later flag propagation.
     */
    public function __construct(
        public ?Rename $companionRename,
        public ?LegacyLivePhotoPair $livePhotoPair,
    ) {
    }
}
