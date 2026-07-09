<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

/**
 * Immutable verify-scan observation for one file carrying an Apple content identifier.
 *
 * The verify flow aggregates these observations per directory and content
 * identifier before the completeness analyzer derives missing-companion
 * findings. This DTO replaces the former `array{pathname, isStill}` contract.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LivePhotoContentIdObservation
{
    /**
     * @param string $pathname Absolute pathname of the observed file
     * @param bool   $isStill  True when the observed file is a still-image side of a Live Photo
     */
    public function __construct(
        public string $pathname,
        public bool $isStill,
    ) {
    }
}
