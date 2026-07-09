<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Video;

use MagicSunday\Renamer\Model\Pipeline\VideoFingerprintMatch;
use SplFileInfo;

/**
 * Compares two video files using stream-level hashes instead of container metadata.
 *
 * The matcher is intentionally low-level: it returns structured evidence about
 * video and audio stream identity but does not decide whether groups should merge.
 * That ownership stays with the cross-group reconciliation policy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface VideoStreamFingerprintMatcherInterface
{
    /**
     * Compares two video files by hashing their stream payloads.
     *
     * @param SplFileInfo $fileA First video file to compare
     * @param SplFileInfo $fileB Second video file to compare
     *
     * @return VideoFingerprintMatch Structured evidence describing video/audio equality
     */
    public function match(SplFileInfo $fileA, SplFileInfo $fileB): VideoFingerprintMatch;
}
