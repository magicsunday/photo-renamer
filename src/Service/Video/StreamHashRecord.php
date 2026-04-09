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
 * Represents one parsed streamhash line emitted by ffmpeg.
 *
 * The streamhash muxer reports each hashed stream as a compact CSV row. This
 * DTO keeps the parsed type/hash pair explicit inside the matcher instead of
 * carrying it as a temporary associative array.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class StreamHashRecord
{
    /**
     * @param StreamHashType $type Stream category emitted by ffmpeg's streamhash muxer
     * @param string         $hash SHA-256 stream hash for that category
     */
    public function __construct(
        public StreamHashType $type,
        public string $hash,
    ) {
    }
}
