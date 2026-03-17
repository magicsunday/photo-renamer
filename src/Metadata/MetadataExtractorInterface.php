<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use SplFileInfo;

/**
 * Contract for extracting temporal metadata (capture date, Live Photo ID) from
 * a media file. Implementations may use EXIF, XMP, QuickTime atoms or any
 * other source. Used by ExifMetadataProvider as the extraction backend.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface MetadataExtractorInterface
{
    /**
     * Extracts temporal metadata from the given file.
     *
     * @param SplFileInfo $file File to extract metadata from
     *
     * @return TemporalMetadata|null Extracted metadata, or null when no relevant fields exist
     *
     * @throws ExifMetadataReadException When the file cannot be read
     */
    public function extractTemporalMetadata(SplFileInfo $file): ?TemporalMetadata;
}
