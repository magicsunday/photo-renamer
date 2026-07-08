<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

/**
 * Shared accessors for metadata signals carried by extracted and cached values.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
trait MetadataSignalAccessorsTrait
{
    /**
     * Returns whether the timezone is ambiguous.
     */
    public function isAmbiguousTimezone(): bool
    {
        return $this->isAmbiguousTimezone;
    }

    /**
     * Returns the Apple still-side Live Photo index from maker notes.
     */
    public function getLivePhotoVideoIndex(): ?int
    {
        return $this->livePhotoVideoIndex;
    }

    /**
     * Returns the camera manufacturer string.
     */
    public function getCameraMake(): ?string
    {
        return $this->cameraMake;
    }

    /**
     * Returns the camera model string.
     */
    public function getCameraModel(): ?string
    {
        return $this->cameraModel;
    }

    /**
     * Returns the device software or firmware version string.
     */
    public function getSoftware(): ?string
    {
        return $this->software;
    }

    /**
     * Returns the signed GPS latitude in decimal degrees.
     */
    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    /**
     * Returns the signed GPS longitude in decimal degrees.
     */
    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    /**
     * Returns the video duration in seconds.
     */
    public function getVideoDurationSeconds(): ?float
    {
        return $this->videoDurationSeconds;
    }

    /**
     * Returns whether the QuickTime container exposes Live Photo marker keys.
     */
    public function hasQuickTimeLivePhotoMarker(): bool
    {
        return $this->hasQuickTimeLivePhotoMarker;
    }
}
