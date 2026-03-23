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
use ImagickPixel;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

use function in_array;
use function is_file;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
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
final readonly class ImagickImageLoader
{
    public function __construct(
        private string $ffmpegBinary = 'ffmpeg',
        private string $ffprobeBinary = 'ffprobe',
        private float $posterFrameSecond = 1.0,
    ) {
        // Set once per process — caps resource usage for maliciously crafted files
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 30);
    }

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

            // Remove alpha channel, flatten multi-layer images
            $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

            if ($img->getNumberImages() > 1) {
                $img->setBackgroundColor('white');
                $merged = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $img->clear();

                return $merged;
            }

            return $img;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extracts multiple frames from a video at 10%, 30%, 50%, 70%, 90% of duration,
     * normalizes each, and stitches them horizontally into a single composite image.
     *
     * This ensures dHash/wHash/color histogram capture the full video content,
     * not just a single poster frame whose color may vary by codec. For trimmed
     * videos, the frames at 50%+ will show completely different content.
     *
     * Falls back to a single frame at ~1s if duration probing fails.
     */
    private function loadVideoFrame(SplFileInfo $file): ?Imagick
    {
        $duration    = $this->probeVideoDuration($file);
        $percentages = [0.10, 0.30, 0.50, 0.70, 0.90];
        $frames      = [];

        if (($duration !== null) && ($duration > 0.5)) {
            foreach ($percentages as $pct) {
                $frame = $this->extractSingleFrame($file, $duration * $pct);

                if ($frame instanceof Imagick) {
                    $frames[] = $frame;
                }
            }
        }

        // Fallback: single frame at ~1s
        if ($frames === []) {
            $frame = $this->extractSingleFrame($file, $this->resolveSeekTime($file));

            if ($frame instanceof Imagick) {
                $frames[] = $frame;
            }
        }

        if ($frames === []) {
            return null;
        }

        if (count($frames) === 1) {
            return $frames[0];
        }

        return $this->stitchFrames($frames);
    }

    /**
     * Extracts and normalizes a single frame at the given seek time.
     */
    private function extractSingleFrame(SplFileInfo $file, float $seekTime): ?Imagick
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'renamer_poster_');

        if (!is_string($tempFile)) {
            return null;
        }

        $posterPath = $tempFile . '.jpg';

        if (is_file($tempFile)) {
            @unlink($tempFile);
        }

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

        if ((!$process->isSuccessful()) || (!is_file($posterPath))) {
            $this->cleanup($posterPath);

            return null;
        }

        try {
            return $this->loadAndNormalize($posterPath);
        } finally {
            $this->cleanup($posterPath);
        }
    }

    /**
     * Stitches multiple frames horizontally into a single composite image.
     * Each frame is resized to 128px square for consistent hash input.
     *
     * @param list<Imagick> $frames
     */
    private function stitchFrames(array $frames): ?Imagick
    {
        try {
            foreach ($frames as $frame) {
                $frame->resizeImage(128, 128, Imagick::FILTER_TRIANGLE, 1.0, false);
            }

            $composite = new Imagick();
            $composite->newImage(128 * count($frames), 128, new ImagickPixel('black'));
            $composite->setImageFormat('png');

            $xOffset = 0;

            foreach ($frames as $frame) {
                $composite->compositeImage($frame, Imagick::COMPOSITE_OVER, $xOffset, 0);
                $frame->clear();
                $xOffset += 128;
            }

            return $composite;
        } catch (Throwable) {
            foreach ($frames as $frame) {
                $frame->clear();
            }

            return null;
        }
    }

    private function resolveSeekTime(SplFileInfo $file): float
    {
        $targetTime = max(0.0, min(2.0, $this->posterFrameSecond));

        if ($targetTime < 0.1) {
            $targetTime = 1.0;
        }

        $duration = $this->probeVideoDuration($file);

        if (($duration !== null) && ($duration > 0.0) && ($targetTime > $duration)) {
            return max(0.0, $duration - 0.1);
        }

        return $targetTime;
    }

    private function isVideo(SplFileInfo $file): bool
    {
        return in_array(
            strtolower($file->getExtension()),
            MediaTypeClassifier::VIDEO_EXTENSIONS,
            true,
        );
    }

    private function cleanup(?string $path): void
    {
        if (is_string($path) && ($path !== '') && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Probes the video duration via ffprobe.
     */
    private function probeVideoDuration(SplFileInfo $file): ?float
    {
        $process = new Process([
            $this->ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $file->getPathname(),
        ]);
        $process->setTimeout(10.0);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        if (($output === '') || !is_numeric($output)) {
            return null;
        }

        $duration = (float) $output;

        return $duration > 0.0 ? $duration : null;
    }
}
