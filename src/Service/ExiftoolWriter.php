<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use DateTimeInterface;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Writes date/time metadata into media files via exiftool. Sets the appropriate
 * tags depending on whether the file is a video (QuickTime) or still image (EXIF).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class ExiftoolWriter
{
    /**
     * Writes the given date/time into the metadata of the specified file.
     * For videos, sets QuickTime:CreateDate and QuickTime:ModifyDate.
     * For images, sets DateTimeOriginal and CreateDate.
     *
     * @param SplFileInfo       $file     File to write metadata to
     * @param DateTimeInterface $dateTime Date/time to write
     * @param bool              $isVideo  Whether the file is a video (MOV/MP4) or still image
     *
     * @return bool True when exiftool reports success, false otherwise
     */
    public function writeDateTime(SplFileInfo $file, DateTimeInterface $dateTime, bool $isVideo): bool
    {
        $args    = $this->buildArguments($file, $dateTime, $isVideo);
        $process = new Process(['exiftool', ...$args]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Builds the exiftool command-line arguments for writing a date.
     *
     * @param SplFileInfo       $file     File to write metadata to
     * @param DateTimeInterface $dateTime Date/time to write
     * @param bool              $isVideo  Whether the file is a video (MOV/MP4) or still image
     *
     * @return list<string>
     */
    public function buildArguments(SplFileInfo $file, DateTimeInterface $dateTime, bool $isVideo): array
    {
        $formattedDate = $dateTime->format('Y:m:d H:i:s');

        if ($isVideo) {
            $args = [
                '-overwrite_original',
                '-QuickTime:CreateDate=' . $formattedDate,
                '-QuickTime:ModifyDate=' . $formattedDate,
            ];
        } else {
            $args = [
                '-overwrite_original',
                '-DateTimeOriginal=' . $formattedDate,
                '-CreateDate=' . $formattedDate,
            ];
        }

        $args[] = $file->getPathname();

        return $args;
    }
}
