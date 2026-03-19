<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use DateTimeInterface;

/**
 * Immutable value object holding the temporal metadata extracted from a media
 * file: the capture timestamp (with potential microsecond precision) and the
 * optional Apple Live Photo content identifier. Produced by MetadataExtractor
 * and cached by ExifMetadataProvider for use throughout the rename pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TemporalMetadata
{
    /**
     * @param DateTimeInterface|null $captureDateTime     Date and time the photo/video was captured, with
     *                                                    potential microsecond precision from EXIF SubSecTime
     * @param string|null            $livePhotoId         Apple Live Photo content identifier linking the
     *                                                    still image to its companion video
     * @param bool                   $isFallbackDateTime  Whether the capture date was derived from the
     *                                                    fallback DateTime tag (0x0132) instead of
     *                                                    DateTimeOriginal (0x9003) or CreateDate (0x9004)
     * @param bool                   $isAmbiguousTimezone Whether the timezone could not be determined
     *                                                    (file modification time altered, cannot distinguish
     *                                                    UTC from local time)
     */
    public function __construct(
        private ?DateTimeInterface $captureDateTime,
        private ?string $livePhotoId,
        private bool $isFallbackDateTime = false,
        private bool $isAmbiguousTimezone = false,
    ) {
    }

    /**
     * Returns the capture timestamp, or null when the file contained no date information.
     */
    public function getCaptureDateTime(): ?DateTimeInterface
    {
        return $this->captureDateTime;
    }

    /**
     * Returns the raw Apple Live Photo content identifier string, or null when
     * the file is not part of a Live Photo pair.
     */
    public function getLivePhotoId(): ?string
    {
        return $this->livePhotoId;
    }

    /**
     * Returns whether the capture date was derived from the fallback DateTime
     * tag (0x0132) instead of DateTimeOriginal (0x9003) or CreateDate (0x9004).
     */
    public function isFallbackDateTime(): bool
    {
        return $this->isFallbackDateTime;
    }

    /**
     * Returns whether the timezone is ambiguous — the file modification time
     * was altered so we cannot determine if the QuickTime timestamp is UTC
     * or local time. These files should be flagged as warnings.
     */
    public function isAmbiguousTimezone(): bool
    {
        return $this->isAmbiguousTimezone;
    }
}
