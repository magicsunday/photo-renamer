<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

/**
 * Represents one normalized still/companion pathname pair in the legacy pipeline.
 *
 * The pair is always oriented from still image to companion video, regardless of
 * which asset happened to be canonical during duplicate processing. That stable
 * orientation keeps later quality-flag propagation free from stringly-typed key
 * conventions and media-type branching.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyLivePhotoPair
{
    /**
     * @param string $stillPath     Pathname of the still image in the pair.
     * @param string $companionPath Pathname of the paired companion video.
     */
    public function __construct(
        public string $stillPath,
        public string $companionPath,
    ) {
    }
}
