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
 * Raw temporal metadata extracted from a media file before normalization.
 * Serves as the intermediate transport between MetadataExtractor (which reads
 * vendor-specific tag structures) and ExifMetadataProvider (which normalizes
 * into the pipeline's ExifData format).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TemporalMetadata
{
    /**
     * @param DateTimeInterface|null $captureDateTime Date and time the photo/video was captured, with
     *                                                potential microsecond precision from EXIF SubSecTime
     * @param string|null            $livePhotoId     Apple Live Photo content identifier linking the
     *                                                still image to its companion video
     */
    public function __construct(
        private ?DateTimeInterface $captureDateTime,
        private ?string $livePhotoId,
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
}
