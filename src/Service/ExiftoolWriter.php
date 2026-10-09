<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\Renamer\Exception\ExiftoolWriteException;
use SplFileInfo;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Writes date/time metadata into media files via exiftool. Sets the appropriate
 * tags depending on whether the file is a video (QuickTime) or still image (EXIF).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExiftoolWriter
{
    /**
     * @param string $binary  Executable path; arguments are always passed without a shell
     * @param float  $timeout Maximum seconds per write before terminating the process
     */
    public function __construct(private string $binary = 'exiftool', private float $timeout = 60.0)
    {
    }

    /**
     * Writes the given date/time into the metadata of the specified file.
     * For videos, sets QuickTime:CreateDate, QuickTime:ModifyDate and Keys:CreationDate.
     * For images, sets DateTimeOriginal and CreateDate.
     *
     * @param SplFileInfo       $file               File to write metadata to
     * @param DateTimeInterface $dateTime           Date/time to write (local time with timezone for videos)
     * @param bool              $isVideo            Whether the file is a video (MOV/MP4) or still image
     * @param bool              $preserveCreateDate When true, only writes Keys:CreationDate without
     *                                              touching QuickTime:CreateDate/ModifyDate (used for
     *                                              timezone disambiguation of existing correct timestamps)
     *
     * @throws ExiftoolWriteException When the process fails, cannot start or exceeds its timeout
     */
    public function writeDateTime(
        SplFileInfo $file,
        DateTimeInterface $dateTime,
        bool $isVideo,
        bool $preserveCreateDate = false,
    ): void {
        $args    = $this->buildArguments($file, $dateTime, $isVideo, $preserveCreateDate);
        $process = new Process([$this->binary, ...$args]);
        $process->setTimeout($this->timeout);
        $process->disableOutput();

        $stderr = '';

        try {
            $process->run(static function (string $type, string $data) use (&$stderr): void {
                if ($type === Process::ERR) {
                    $stderr .= substr($data, 0, 2048 - strlen($stderr));
                }
            });
        } catch (ProcessTimedOutException $exception) {
            throw new ExiftoolWriteException(sprintf('Exiftool timed out after %g seconds.', $this->timeout), $exception->getCode(), previous: $exception);
        } catch (ExceptionInterface $exception) {
            throw new ExiftoolWriteException('Exiftool process failed: ' . substr($exception->getMessage(), 0, 2048), $exception->getCode(), previous: $exception);
        }

        if (!$process->isSuccessful()) {
            throw new ExiftoolWriteException(sprintf(
                'Exiftool exited with code %s: %s',
                (string) $process->getExitCode(),
                trim($stderr) !== '' ? trim($stderr) : 'No error output.',
            ));
        }
    }

    /**
     * Builds the exiftool command-line arguments for writing a date.
     *
     * @param SplFileInfo       $file               File to write metadata to
     * @param DateTimeInterface $dateTime           Date/time to write (local time with timezone for videos)
     * @param bool              $isVideo            Whether the file is a video (MOV/MP4) or still image
     * @param bool              $preserveCreateDate When true, only writes Keys:CreationDate
     *
     * @return list<string>
     */
    public function buildArguments(
        SplFileInfo $file,
        DateTimeInterface $dateTime,
        bool $isVideo,
        bool $preserveCreateDate = false,
    ): array {
        if ($isVideo) {
            $localFormatted = $dateTime->format('Y:m:d H:i:sP');

            if ($preserveCreateDate) {
                // Only add Keys:CreationDate with offset — leave QuickTime:CreateDate untouched.
                // Used when the existing CreateDate is correct but lacks timezone info.
                $args = [
                    '-overwrite_original',
                    '-Keys:CreationDate=' . $localFormatted,
                ];
            } else {
                // QuickTime:CreateDate/ModifyDate are always UTC (Mac epoch).
                // Keys:CreationDate carries the local time with timezone offset.
                // Track/Media dates are NOT touched — they may not exist in all
                // files and creating them would add unexpected metadata.
                $utcDate = DateTimeImmutable::createFromInterface($dateTime)
                    ->setTimezone(new DateTimeZone('UTC'));
                $utcFormatted = $utcDate->format('Y:m:d H:i:s');

                $args = [
                    '-overwrite_original',
                    '-QuickTime:CreateDate=' . $utcFormatted,
                    '-QuickTime:ModifyDate=' . $utcFormatted,
                    '-Keys:CreationDate=' . $localFormatted,
                ];
            }
        } else {
            $formattedDate = $dateTime->format('Y:m:d H:i:s');

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
