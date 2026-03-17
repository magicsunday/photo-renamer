<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use DateTimeInterface;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use Override;
use SplFileInfo;

use function basename;

/**
 * Generates target filenames by formatting the EXIF DateTimeOriginal value
 * according to a user-supplied PHP date() pattern (e.g. "Ymd_His"). The capture
 * timestamp already includes subsecond precision from the metadata extractor,
 * so formatting is a single {@see DateTimeInterface::format()} call.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
readonly class ExifDateFilenameStrategy implements LivePhotoAwareRenameStrategyInterface
{
    /**
     * @param string               $targetFilenamePattern PHP date() format string defining the target basename
     * @param ExifMetadataProvider $exifMetadataProvider  Caching EXIF metadata accessor
     */
    public function __construct(
        private string $targetFilenamePattern,
        private ExifMetadataProvider $exifMetadataProvider,
    ) {
    }

    /**
     * Formats the EXIF capture date into a target filename using the configured pattern.
     * Returns null when the file has no usable EXIF date, causing it to be skipped
     * by the pipeline.
     *
     * @param SplFileInfo $splFileInfo Source file to read EXIF data from
     *
     * @return string|null Target filename with extension, or null when EXIF date is absent
     *
     * @throws TargetFilenameException When reading EXIF metadata fails
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): ?string
    {
        $captureDateTime = $this->exifMetadataProvider->getCaptureDateTime($splFileInfo);

        if (!$captureDateTime instanceof DateTimeInterface) {
            return null;
        }

        $targetBasename = basename($captureDateTime->format($this->targetFilenamePattern));

        return $targetBasename . '.' . $splFileInfo->getExtension();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        return $this->exifMetadataProvider->getContentIdentifier($splFileInfo);
    }
}
