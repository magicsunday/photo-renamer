<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use Imagick;
use Override;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

use function in_array;
use function is_file;
use function is_string;
use function max;
use function min;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Loads and normalizes images via Imagick with proper color space handling.
 * For videos, extracts a poster frame via ffmpeg, then normalizes through Imagick.
 *
 * The normalization pipeline ensures that the same capture in different formats
 * (JPG in sRGB vs HEIC in Display P3) produces identical pixel values:
 *
 *   autoOrient → strip → sRGB → removeAlpha → flatten
 *
 * Order matters: autoOrient() MUST come before stripImage() — stripping first
 * removes the EXIF orientation tag so the rotation is never applied.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ImagickImageLoader implements ImagickImageLoaderInterface
{
    use VideoDurationProbeTrait;

    private const array VIDEO_EXTENSIONS = ['mov', 'mp4', 'avi', 'mkv', 'webm', 'm4v', '3gp'];

    public function __construct(
        private string $ffmpegBinary = 'ffmpeg',
        private string $ffprobeBinary = 'ffprobe',
        private float $posterFrameSecond = 1.0,
    ) {
    }

    #[Override]
    public function loadNormalized(SplFileInfo $file, ?int $maxResolution = null): ?Imagick
    {
        if (!$file->isFile()) {
            return null;
        }

        if ($this->isVideo($file)) {
            return $this->loadVideoFrame($file);
        }

        return $this->loadAndNormalize($file->getPathname(), $maxResolution);
    }

    /**
     * Loads an image file and applies the full normalization pipeline.
     */
    private function loadAndNormalize(string $path, ?int $maxResolution = null): ?Imagick
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $img = new Imagick();

            // Hint the JPEG decoder to load at reduced resolution.
            // For a 3008×2000 image with maxResolution=256, libjpeg decodes
            // at 1/8 (376×250) instead of full res — ~10× faster.
            if ($maxResolution !== null) {
                $img->setOption('jpeg:size', $maxResolution . 'x' . $maxResolution);
            }

            $img->readImage($path);

            // Use only the first frame (animated GIF, multi-page HEIC)
            if ($img->getNumberImages() > 1) {
                $img->setFirstIterator();
            }

            // Apply EXIF rotation BEFORE stripping metadata
            $img->autoOrient();
            $img->stripImage();

            // Normalize to sRGB colorspace
            try {
                $img->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            } catch (Throwable) {
                // Some builds/formats don't support colorspace conversion
            }

            // Remove alpha channel, flatten layers
            $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $img->setBackgroundColor('white');

            return $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extracts a poster frame from a video via ffmpeg, then normalizes it.
     */
    private function loadVideoFrame(SplFileInfo $file): ?Imagick
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'renamer_poster_');

        if (!is_string($tempFile)) {
            return null;
        }

        $posterPath = $tempFile . '.jpg';

        if (is_file($tempFile)) {
            @unlink($tempFile);
        }

        $seekTime = $this->resolveSeekTime($file);

        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-loglevel',
            'error',
            '-ss',
            sprintf('%.3f', $seekTime),
            '-i',
            $file->getPathname(),
            '-frames:v',
            '1',
            $posterPath,
        ]);
        $process->setTimeout(20.0);

        try {
            $process->run();
        } catch (Throwable) {
            $this->cleanup($posterPath);

            return null;
        }

        if (!$process->isSuccessful() || !is_file($posterPath)) {
            $this->cleanup($posterPath);

            return null;
        }

        try {
            return $this->loadAndNormalize($posterPath);
        } finally {
            $this->cleanup($posterPath);
        }
    }

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

    private function isVideo(SplFileInfo $file): bool
    {
        return in_array(
            strtolower($file->getExtension()),
            self::VIDEO_EXTENSIONS,
            true,
        );
    }

    private function cleanup(?string $path): void
    {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
