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
 * Carries the primary video and audio stream hashes for one video file.
 *
 * The matcher keeps this DTO internal to the video fingerprinting boundary so
 * stream identity evidence is modeled explicitly instead of traveling as a
 * loosely typed array shape through the cache and comparison logic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class VideoStreamFingerprint
{
    /**
     * @param string|null $videoHash SHA-256 of the first video stream, or null when unavailable
     * @param string|null $audioHash SHA-256 of the first audio stream, or null when unavailable
     * @param bool        $hasAudio  Indicates whether an audio stream was observed at all
     */
    public function __construct(
        public ?string $videoHash,
        public ?string $audioHash,
        public bool $hasAudio,
    ) {
    }
}
