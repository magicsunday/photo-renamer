<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Video;

/**
 * Enumerates the ffmpeg stream categories the matcher cares about.
 *
 * Only primary video and audio streams influence exact duplicate decisions.
 * Keeping the short ffmpeg identifiers in an enum avoids scattering raw
 * `'v'` and `'a'` literals through the matcher.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum StreamHashType: string
{
    /**
     * The first video stream of the container.
     */
    case Video = 'v';

    /**
     * The first audio stream of the container.
     */
    case Audio = 'a';
}
