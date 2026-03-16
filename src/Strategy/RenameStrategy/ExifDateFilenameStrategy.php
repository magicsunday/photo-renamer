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
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class ExifDateFilenameStrategy implements LivePhotoAwareRenameStrategyInterface
{
    public function __construct(private readonly string $targetFilenamePattern, private readonly ExifMetadataProvider $exifMetadataProvider)
    {
    }

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

    #[Override]
    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        $identifier = $this->exifMetadataProvider->getContentIdentifier($splFileInfo);

        return $identifier?->getValue();
    }

    /**
     * Returns the formatted EXIF date of the specified file, formatted according to the specified pattern.
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
