<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use Imagick;
use MagicSunday\Renamer\Helper\NativeToolError;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function chmod;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[UsesClass(NullProgressReporter::class)]
#[CoversClass(ImagickImageLoader::class)]
#[UsesClass(NativeToolError::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class ImagickImageLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/imagick-loader-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    /**
     * Missing video capabilities are reported once each across repeated loads,
     * keep failed analysis unavailable, and leave source bytes untouched.
     *
     * @return void
     */
    #[Test]
    public function missingVideoToolsProduceBoundedDiagnostics(): void
    {
        $path = $this->tempDir . '/synthetic.mov';
        file_put_contents($path, 'synthetic media');
        $messages = [];
        $reporter = $this->createMock(ProgressReporterInterface::class);
        $reporter->expects(self::exactly(2))->method('error')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $loader = new ImagickImageLoader(new MediaTypeClassifier(), $reporter, $this->tempDir . '/missing-ffmpeg.sh', $this->tempDir . '/missing-ffprobe.sh');

        self::assertNull($loader->loadNormalized(new SplFileInfo($path)));
        self::assertNull($loader->loadNormalized(new SplFileInfo($path)));
        self::assertStringContainsString('ffprobe', $messages[0]);
        self::assertStringContainsString('ffmpeg', $messages[1]);
        self::assertSame('synthetic media', file_get_contents($path));
    }

    /**
     * A native child exiting unsuccessfully exposes its exit code and stderr;
     * independent JPEG decoding remains usable without either video binary.
     *
     * @return void
     */
    #[Test]
    public function nativeFailureIsVisibleAndDoesNotBlockImages(): void
    {
        $tool = $this->tempDir . '/failing-tool.sh';
        file_put_contents($tool, "#!/bin/sh\nprintf 'synthetic decoder failure' >&2\nexit 47\n");
        chmod($tool, 0700);
        $video = $this->tempDir . '/synthetic.mov';
        file_put_contents($video, 'synthetic media');
        $messages = [];
        $reporter = $this->createMock(ProgressReporterInterface::class);
        $reporter->expects(self::exactly(2))->method('error')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $loader = new ImagickImageLoader(new MediaTypeClassifier(), $reporter, $tool, $tool);
        self::assertNull($loader->loadNormalized(new SplFileInfo($video)));

        foreach ($messages as $message) {
            self::assertStringContainsString('47', $message);
            self::assertStringContainsString('synthetic decoder failure', $message);
        }

        $image = new Imagick();
        $image->newImage(16, 16, 'red', 'jpeg');

        $path = $this->tempDir . '/still.jpg';
        $image->writeImage($path);
        $image->clear();

        $result = $loader->loadNormalized(new SplFileInfo($path));
        self::assertInstanceOf(Imagick::class, $result);
        $result->clear();
    }

    private function createLoader(): ImagickImageLoader
    {
        return new ImagickImageLoader(new MediaTypeClassifier(), new NullProgressReporter());
    }

    /**
     * Verifies that loadNormalized returns a valid Imagick instance for a standard JPEG file.
     * The image should have the expected dimensions after normalization.
     */
    #[Test]
    public function loadNormalizedReturnsImagickForValidJpeg(): void
    {
        $path = $this->tempDir . '/valid.jpg';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i color=red:s=128x128 -frames:v 1 %s 2>/dev/null',
            escapeshellarg($path),
        ));

        $loader = $this->createLoader();
        $result = $loader->loadNormalized(new SplFileInfo($path));

        self::assertInstanceOf(Imagick::class, $result);
        self::assertSame(128, $result->getImageWidth());
        self::assertSame(128, $result->getImageHeight());
    }

    /**
     * Verifies that loadNormalized returns null if the provided file path does not exist.
     */
    #[Test]
    public function loadNormalizedReturnsNullForNonExistentFile(): void
    {
        $loader = $this->createLoader();
        $result = $loader->loadNormalized(new SplFileInfo('/does/not/exist/photo.jpg'));

        self::assertNull($result);
    }

    /**
     * Verifies that loadNormalized returns null if the file is not a valid image (corrupt).
     */
    #[Test]
    public function loadNormalizedReturnsNullForCorruptFile(): void
    {
        $path = $this->tempDir . '/corrupt.jpg';
        file_put_contents($path, 'this is not a jpeg file at all - just garbage bytes');

        $loader = $this->createLoader();
        $result = $loader->loadNormalized(new SplFileInfo($path));

        self::assertNull($result);
    }

    /**
     * Verifies that the maxResolution hint is correctly passed to the JPEG decoder.
     * The resulting image should be scaled down according to the hint to save resources.
     */
    #[Test]
    public function loadNormalizedRespectsMaxResolutionHint(): void
    {
        $path = $this->tempDir . '/large.jpg';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i color=blue:s=256x256 -frames:v 1 %s 2>/dev/null',
            escapeshellarg($path),
        ));

        $loader = $this->createLoader();
        $result = $loader->loadNormalized(new SplFileInfo($path), 64);

        self::assertInstanceOf(Imagick::class, $result);

        // JPEG decoder hint rounds to nearest 1/2/4/8 scale factor,
        // so dimensions should be at most 64 pixels
        self::assertLessThanOrEqual(64, $result->getImageWidth());
        self::assertLessThanOrEqual(64, $result->getImageHeight());
    }

    /**
     * Verifies that video files are correctly handled by extracting frames.
     * The resulting Imagick instance should represent a stitched composite of multiple frames.
     */
    #[Test]
    public function loadNormalizedHandlesVideoFiles(): void
    {
        $path = $this->tempDir . '/clip.mov';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i color=green:s=64x64:d=0.5 -c:v libx264 -f mov %s 2>/dev/null',
            escapeshellarg($path),
        ));

        $loader = $this->createLoader();
        $result = $loader->loadNormalized(new SplFileInfo($path));

        self::assertInstanceOf(Imagick::class, $result);
        self::assertGreaterThan(0, $result->getImageWidth());
        self::assertGreaterThan(0, $result->getImageHeight());
    }
}
