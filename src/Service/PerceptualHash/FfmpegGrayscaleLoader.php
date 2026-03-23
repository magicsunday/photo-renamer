<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use Override;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

use function array_fill;
use function max;
use function min;
use function ord;
use function sprintf;
use function strlen;

/**
 * Loads grayscale pixel matrices from images and videos using ffmpeg.
 * Supports all formats ffmpeg can decode (JPEG, HEIC, MOV, MP4, etc.)
 * without requiring GD or Imagick PHP extensions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FfmpegGrayscaleLoader implements FfmpegGrayscaleLoaderInterface
{
    use VideoDurationProbeTrait;

    private const array VIDEO_EXTENSIONS = ['mov', 'mp4', 'avi', 'mkv', 'webm', 'm4v', '3gp'];

    public function __construct(
        private string $ffmpegBinary = 'ffmpeg',
        private string $ffprobeBinary = 'ffprobe',
        private float $posterFrameSecond = 1.0,
    ) {
    }

    /**
     * @return array<int, array<int, float>>|null
     */
    #[Override]
    public function loadGrayscaleMatrix(SplFileInfo $file, int $width, int $height): ?array
    {
        if (!$file->isFile()) {
            return null;
        }

        $isVideo = $this->isVideo($file);

        $command = [$this->ffmpegBinary, '-y', '-loglevel', 'error'];

        if ($isVideo) {
            $seekTime  = $this->resolveSeekTime($file);
            $command[] = '-ss';
            $command[] = sprintf('%.3f', $seekTime);
        }

        $command[] = '-i';
        $command[] = $file->getPathname();

        if ($isVideo) {
            $command[] = '-frames:v';
            $command[] = '1';
        }

        $command[] = '-vf';
        $command[] = sprintf('scale=%d:%d,format=gray', $width, $height);
        $command[] = '-f';
        $command[] = 'rawvideo';
        $command[] = '-pix_fmt';
        $command[] = 'gray';
        $command[] = 'pipe:1';

        $process = new Process($command);
        $process->setTimeout(30.0);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $rawBytes = $process->getOutput();
        $expected = $width * $height;

        if (strlen($rawBytes) !== $expected) {
            return null;
        }

        return $this->bytesToMatrix($rawBytes, $width, $height);
    }

    /**
     * Converts raw grayscale bytes to a luma matrix.
     *
     * @return array<int, array<int, float>>
     */
    private function bytesToMatrix(string $bytes, int $width, int $height): array
    {
        $matrix = array_fill(0, $height, array_fill(0, $width, 0.0));
        $index  = 0;

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $matrix[$y][$x] = (float) ord($bytes[$index]);
                ++$index;
            }
        }

        return $matrix;
    }

    private function isVideo(SplFileInfo $file): bool
    {
        return in_array(
            strtolower($file->getExtension()),
            self::VIDEO_EXTENSIONS,
            true,
        );
    }

    /**
     * Determines the seek time for poster frame extraction,
     * clamped to the video's duration.
     */
    private function resolveSeekTime(SplFileInfo $file): float
    {
        $targetTime = max(0.0, min(2.0, $this->posterFrameSecond));

        if ($targetTime < 0.1) {
            $targetTime = 1.0;
        }

        $duration = $this->probeVideoDuration($file);

        if ($duration !== null && $duration > 0.0 && $targetTime > $duration) {
            return max(0.0, $duration - 0.1);
        }

        return $targetTime;
    }
}
