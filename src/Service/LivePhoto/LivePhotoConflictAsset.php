<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

/**
 * Immutable heuristic input record for Live Photo conflict detection.
 *
 * The conflict detector evaluates still/video candidates across several
 * independent signals such as capture second, content identifier, device
 * identity, GPS location, and short video duration. This DTO makes those
 * semantics explicit instead of passing large anonymous array-shapes through
 * the matching logic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LivePhotoConflictAsset
{
    /**
     * @param string      $pathname             Absolute pathname used as the stable conflict marker key
     * @param float       $captureTimestamp     Microsecond-precision capture timestamp for future tie-break analysis
     * @param int         $captureSecond        Whole-second capture timestamp used for exact/fallback bucketing
     * @param string|null $contentIdentifier    Normalized Live Photo content identifier when present
     * @param string      $deviceKey            Normalized comparable device identity
     * @param float|null  $latitude             Latitude used for GPS-distance filtering
     * @param float|null  $longitude            Longitude used for GPS-distance filtering
     * @param float|null  $videoDurationSeconds Video duration for short-live-photo heuristics; null for stills
     */
    public function __construct(
        public string $pathname,
        public float $captureTimestamp,
        public int $captureSecond,
        public ?string $contentIdentifier,
        public string $deviceKey,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $videoDurationSeconds,
    ) {
    }
}
