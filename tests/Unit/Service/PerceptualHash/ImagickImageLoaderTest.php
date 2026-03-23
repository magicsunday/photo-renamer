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
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function escapeshellarg;
use function exec;
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
#[CoversClass(ImagickImageLoader::class)]
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

    private function createLoader(): ImagickImageLoader
    {
        return new ImagickImageLoader();
    }

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

    #[Test]
    public function loadNormalizedReturnsNullForNonExistentFile(): void
    {
        $loader = $this->createLoader();
        $result = $loader->loadNormalized(new SplFileInfo('/does/not/exist/photo.jpg'));

        self::assertNull($result);
    }

    #[Test]
    public function loadNormalizedReturnsNullForCorruptFile(): void
    {
        $path = $this->tempDir . '/corrupt.jpg';
        file_put_contents($path, 'this is not a jpeg file at all - just garbage bytes');

        $loader = $this->createLoader();
        $result = $loader->loadNormalized(new SplFileInfo($path));

        self::assertNull($result);
    }

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
