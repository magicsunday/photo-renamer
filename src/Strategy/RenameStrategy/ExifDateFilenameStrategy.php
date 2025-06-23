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

    /**
     * Constructor.
     *
     * @param string $targetFilenamePattern
     */
    public function __construct(string $targetFilenamePattern)
    {
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
        $exifData = @exif_read_data($splFileInfo->getPathname());

        // if ($exifData !== false) {
        //     $this->io->text('Extract EXIF data from: ' . $splFileInfo->getPathname());
        // }

        // Ignore files without EXIF data
        if ($exifData === false) {
            return null;
        }

        if (!isset($exifData['DateTimeOriginal'])) {
            return null;
        }

        // $this->io->text('=> Found "DateTimeOriginal" => ' . $exifData['DateTimeOriginal']);

        // Store the date and time the image/video was recorded

        /** @var string $exifDateTimeOriginal */
        $exifDateTimeOriginal = $exifData['DateTimeOriginal'];

        /** @var string $exifSubSecTimeOriginal */
        $exifSubSecTimeOriginal = $exifData['SubSecTimeOriginal'] ?? '';

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
