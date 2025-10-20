<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use DateTime;
use Exception;
use Override;
use SplFileInfo;

use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class ExifDateFilenameStrategy implements RenameStrategyInterface
{
    /**
     * @var string
     */
    private readonly string $targetFilenamePattern;

    public function __construct(
        string $targetFilenamePattern,
        private readonly ExifMetadataProvider $exifMetadataProvider,
    ) {
        $this->targetFilenamePattern = $targetFilenamePattern;
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

    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        $identifier = $this->exifMetadataProvider->getContentIdentifier($splFileInfo);

        return $identifier?->getValue();
    }

    /**
     * Returns the formatted EXIF date of the specified file, formatted according to the specified pattern.
     *
     * @param string      $pattern
     * @param SplFileInfo $splFileInfo
     *
     * @return string|null
     */
    private function getExifDateFormatted(
        string $pattern,
        SplFileInfo $splFileInfo,
    ): ?string {
        // Look up EXIF data
        $exifData = $this->exifMetadataProvider->getExifData($splFileInfo);

        if ($exifData === null) {
            return null;
        }

        $exifDateTimeOriginal  = $exifData->getDateTimeOriginal();
        $exifSubSecTimeOriginal = $exifData->getSubSecTimeOriginal() ?? '';

        try {
            $dateTimeOriginal = new DateTime($exifDateTimeOriginal);

            if ($exifSubSecTimeOriginal !== '') {
                if (strlen($exifSubSecTimeOriginal) > 4) {
                    $dateTimeOriginal->modify('+' . $exifSubSecTimeOriginal . ' Microseconds');
                } else {
                    $dateTimeOriginal->modify('+' . $exifSubSecTimeOriginal . ' Milliseconds');
                }
            }
        } catch (Exception) {
            // $this->io->warning('=> Invalid EXIF date format in "DateTimeOriginal".');

            return null;
        }

        return $dateTimeOriginal->format($pattern);
    }

}
