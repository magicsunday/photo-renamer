<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use DateTimeImmutable;
use Exception;
use MagicSunday\Renamer\Metadata\ExifData;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use Override;
use SplFileInfo;

use function basename;
use function str_pad;
use function substr;

/**
 * Generates target filenames by formatting the EXIF DateTimeOriginal value
 * according to a user-supplied PHP date() pattern (e.g. "Ymd_His"). Supports
 * sub-second precision and exposes Live Photo content identifiers for
 * companion pairing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class ExifDateFilenameStrategy implements LivePhotoAwareRenameStrategyInterface
{
    /**
     * @param string                $targetFilenamePattern PHP date() format string defining the target basename
     * @param ExifMetadataProvider  $exifMetadataProvider  Caching EXIF metadata accessor
     */
    public function __construct(private readonly string $targetFilenamePattern, private readonly ExifMetadataProvider $exifMetadataProvider)
    {
    }

    /**
     * Formats the EXIF capture date into a target filename using the configured pattern.
     * Returns null when the file has no usable EXIF date, causing it to be skipped
     * by the pipeline.
     *
     * @param SplFileInfo $splFileInfo Source file to read EXIF data from
     *
     * @return string|null Target filename with extension, or null when EXIF date is absent
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): ?string
    {
        // Create a new filename based on the formatted value of the EXIF field "DateTimeOriginal".
        $targetBasename = $this->getExifDateFormatted($this->targetFilenamePattern, $splFileInfo);

        if ($targetBasename === null) {
            return null;
        }

        return $targetBasename . '.' . $splFileInfo->getExtension();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        $identifier = $this->exifMetadataProvider->getContentIdentifier($splFileInfo);

        return $identifier?->getValue();
    }

    /**
     * Reads the EXIF date from the file, combines it with sub-second precision,
     * and formats the result using the given PHP date() pattern. Returns null when
     * the file lacks EXIF data or the date string cannot be parsed.
     *
     * @param string      $pattern     PHP date() format string
     * @param SplFileInfo $splFileInfo File to extract the EXIF date from
     *
     * @return string|null Formatted basename (without extension), or null on failure
     */
    private function getExifDateFormatted(
        string $pattern,
        SplFileInfo $splFileInfo,
    ): ?string {
        // Look up EXIF data
        $exifData = $this->exifMetadataProvider->getExifData($splFileInfo);

        if (!$exifData instanceof ExifData) {
            return null;
        }

        $exifDateTimeOriginal   = $exifData->getDateTimeOriginal();
        $exifSubSecTimeOriginal = $this->normaliseSubSecondValue(
            $exifData->getSubSecTimeOriginal()
        );

        try {
            $dateTimeOriginal = new DateTimeImmutable($exifDateTimeOriginal);

            if ($exifSubSecTimeOriginal !== null) {
                $microseconds     = substr(str_pad($exifSubSecTimeOriginal, 6, '0'), 0, 6);
                $dateTimeOriginal = $dateTimeOriginal->modify('+' . $microseconds . ' microseconds');
            }
        } catch (Exception) {
            // $this->io->warning('=> Invalid EXIF date format in "DateTimeOriginal".');

            return null;
        }

        return basename($dateTimeOriginal->format($pattern));
    }

    /**
     * Strips non-digit characters from the sub-second value. Some cameras embed
     * whitespace or punctuation in the SubSecTimeOriginal tag; this ensures only
     * numeric digits remain.
     *
     * @param string|null $value Raw sub-second string from EXIF metadata
     *
     * @return string|null Digits-only string, or null when input is null or empty
     */
    private function normaliseSubSecondValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        return $digits;
    }
}
