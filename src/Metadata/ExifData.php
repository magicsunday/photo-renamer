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
 * Immutable value object holding the EXIF fields relevant to the rename pipeline:
 * the original capture timestamp, optional sub-second precision, and the Live Photo
 * content identifier. Produced by ExifMetadataProvider after normalizing raw
 * metadata extracted via the MetadataExtractor.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExifData
{
    /**
     * @param string                 $dateTimeOriginal   Capture date in "Y:m:d H:i:s" format
     * @param string|null            $subSecTimeOriginal Sub-second precision as milliseconds (3 digits) or
     *                                                   microseconds (6 digits), null when not available
     * @param ContentIdentifier|null $contentIdentifier  Apple Live Photo content ID linking photo and video
     */
    public function __construct(
        private string $dateTimeOriginal,
        private ?string $subSecTimeOriginal,
        private ?ContentIdentifier $contentIdentifier,
    ) {
    }

    /**
     * Returns the EXIF DateTimeOriginal in "Y:m:d H:i:s" format, used as the
     * basis for generating target filenames in EXIF-date-based rename strategies.
     */
    public function getDateTimeOriginal(): string
    {
        return $this->dateTimeOriginal;
    }

    /**
     * Returns the sub-second component of the capture time as a string of 3 (milliseconds)
     * or 6 (microseconds) digits, or null when the source file had no sub-second data.
     */
    public function getSubSecTimeOriginal(): ?string
    {
        return $this->subSecTimeOriginal;
    }

    /**
     * Returns the Live Photo content identifier, or null when the file is not part
     * of an Apple Live Photo pair.
     */
    public function getContentIdentifier(): ?ContentIdentifier
    {
        return $this->contentIdentifier;
    }
}
