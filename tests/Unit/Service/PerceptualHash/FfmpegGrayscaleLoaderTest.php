<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\PerceptualHash;

use MagicSunday\Renamer\Service\PerceptualHash\FfmpegGrayscaleLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sys_get_temp_dir;
use function uniqid;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(FfmpegGrayscaleLoader::class)]
final class FfmpegGrayscaleLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ffmpeg-loader-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    #[Test]
    public function loadGrayscaleMatrixReturnsCorrectDimensionsForJpeg(): void
    {
        $path = $this->tempDir . '/test.jpg';
        exec(sprintf('ffmpeg -y -f lavfi -i color=white:s=100x100 -frames:v 1 %s 2>/dev/null', escapeshellarg($path)));

        $loader = new FfmpegGrayscaleLoader();
        $matrix = $loader->loadGrayscaleMatrix(new SplFileInfo($path), 9, 8);

        self::assertNotNull($matrix);
        self::assertCount(8, $matrix, 'Matrix must have 8 rows');
        self::assertCount(9, $matrix[0], 'Each row must have 9 columns');
    }

    #[Test]
    public function loadGrayscaleMatrixReturnsCorrectDimensionsForVideo(): void
    {
        $path = $this->tempDir . '/test.mov';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i color=black:s=16x16:d=0.5 -c:v libx264 -f mov %s 2>/dev/null',
            escapeshellarg($path),
        ));

        $loader = new FfmpegGrayscaleLoader();
        $matrix = $loader->loadGrayscaleMatrix(new SplFileInfo($path), 9, 8);

        self::assertNotNull($matrix);
        self::assertCount(8, $matrix);
        self::assertCount(9, $matrix[0]);
    }

    #[Test]
    public function loadGrayscaleMatrixReturnsNullForNonExistentFile(): void
    {
        $loader = new FfmpegGrayscaleLoader();
        $matrix = $loader->loadGrayscaleMatrix(new SplFileInfo('/does/not/exist.jpg'), 9, 8);

        self::assertNull($matrix);
    }

    #[Test]
    public function loadGrayscaleMatrixReturnsNullForCorruptedFile(): void
    {
        $path = $this->tempDir . '/corrupt.jpg';
        file_put_contents($path, 'not-a-real-image');

        $loader = new FfmpegGrayscaleLoader();
        $matrix = $loader->loadGrayscaleMatrix(new SplFileInfo($path), 9, 8);

        self::assertNull($matrix);
    }

    #[Test]
    public function loadGrayscaleMatrixReturnsLumaValuesInExpectedRange(): void
    {
        $path = $this->tempDir . '/gray.jpg';
        exec(sprintf('ffmpeg -y -f lavfi -i color=gray:s=32x32 -frames:v 1 %s 2>/dev/null', escapeshellarg($path)));

        $loader = new FfmpegGrayscaleLoader();
        $matrix = $loader->loadGrayscaleMatrix(new SplFileInfo($path), 9, 8);

        self::assertNotNull($matrix);

        foreach ($matrix as $row) {
            foreach ($row as $luma) {
                self::assertGreaterThanOrEqual(0.0, $luma);
                self::assertLessThanOrEqual(255.0, $luma);
            }
        }
    }

    #[Test]
    public function loadGrayscaleMatrixWhiteImageHasHighLuma(): void
    {
        $path = $this->tempDir . '/white.jpg';
        exec(sprintf('ffmpeg -y -f lavfi -i color=white:s=32x32 -frames:v 1 %s 2>/dev/null', escapeshellarg($path)));

        $loader = new FfmpegGrayscaleLoader();
        $matrix = $loader->loadGrayscaleMatrix(new SplFileInfo($path), 9, 8);

        self::assertNotNull($matrix);

        // White image should have luma values close to 255
        $avgLuma = 0.0;
        $count   = 0;

        foreach ($matrix as $row) {
            foreach ($row as $luma) {
                $avgLuma += $luma;
                ++$count;
            }
        }

        $avgLuma /= $count;
        self::assertGreaterThan(240.0, $avgLuma, 'White image should have high average luma');
    }

    #[Test]
    public function loadGrayscaleMatrixBlackImageHasLowLuma(): void
    {
        $path = $this->tempDir . '/black.jpg';
        exec(sprintf('ffmpeg -y -f lavfi -i color=black:s=32x32 -frames:v 1 %s 2>/dev/null', escapeshellarg($path)));

        $loader = new FfmpegGrayscaleLoader();
        $matrix = $loader->loadGrayscaleMatrix(new SplFileInfo($path), 9, 8);

        self::assertNotNull($matrix);

        $avgLuma = 0.0;
        $count   = 0;

        foreach ($matrix as $row) {
            foreach ($row as $luma) {
                $avgLuma += $luma;
                ++$count;
            }
        }

        $avgLuma /= $count;
        self::assertLessThan(20.0, $avgLuma, 'Black image should have low average luma');
    }
}
