<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * IMPORTANT: We define a namespaced function stub for exif_read_data in the
 * same namespace as the class under test. Because the class refers to
 * exif_read_data without a leading backslash, PHP's namespaced function
 * resolution will prefer this function over the global one. This allows us to
 * provide deterministic EXIF data without relying on the PHP EXIF extension or
 * crafting binary EXIF segments.
 */
namespace MagicSunday\Renamer\Strategy\RenameStrategy {
    /**
     * Internal test stub state holder for EXIF responses.
     */
    final class ExifReadDataStub
    {
        /** @var array<string, array|false> */
        public static array $map = [];

        public static function set(string $path, array|false $data): void
        {
            self::$map[$path] = $data;
        }

        public static function reset(): void
        {
            self::$map = [];
        }
    }

    /**
     * Stub replacement for exif_read_data used by ExifDateFilenameStrategy::getExifData().
     *
     * @param string $filename
     * @return array|false
     */
    function exif_read_data(string $filename): array|false
    {
        return ExifReadDataStub::$map[$filename] ?? false;
    }
}

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy {

use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifReadDataStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sprintf;
use function uniqid;

#[CoversClass(ExifDateFilenameStrategy::class)]
class ExifDateFilenameStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        // Ensure the stub map is reset after each test
        ExifReadDataStub::reset();
    }

    #[Test]
    #[DataProvider('exifDateProvider')]
    public function itGeneratesFilenameFromExifDate(
        string $dateTimeOriginal,
        ?string $subSecTimeOriginal,
        string $pattern,
        string $extension,
        string $expected,
        string $description,
    ): void {
        $path = '/virtual/' . uniqid('exif_', true) . '.' . $extension;

        // Provide deterministic EXIF data via stub
        $exif = [
            'DateTimeOriginal' => $dateTimeOriginal,
        ];

        if ($subSecTimeOriginal !== null) {
            $exif['SubSecTimeOriginal'] = $subSecTimeOriginal;
        }

        ExifReadDataStub::set($path, $exif);

        $file     = new SplFileInfo($path);
        $strategy = new ExifDateFilenameStrategy($pattern);

        self::assertSame(
            $expected,
            $strategy->generateFilename($file),
            sprintf('Failed for case: %s', $description)
        );
    }

    #[Test]
    public function itReturnsNullWhenNoExifData(): void
    {
        $path = '/virtual/' . uniqid('exif_', true) . '.jpg';

        // No EXIF data provided for this path -> stub returns false
        ExifReadDataStub::set($path, false);

        $file     = new SplFileInfo($path);
        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

        self::assertNull($strategy->generateFilename($file));
    }

    #[Test]
    public function itReturnsNullWhenDateTimeOriginalMissing(): void
    {
        $path = '/virtual/' . uniqid('exif_', true) . '.jpg';

        // EXIF data present but without DateTimeOriginal
        ExifReadDataStub::set($path, ['SomeOtherTag' => 'value']);

        $file     = new SplFileInfo($path);
        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

        self::assertNull($strategy->generateFilename($file));
    }

    #[Test]
    public function itReturnsNullOnInvalidDate(): void
    {
        $path = '/virtual/' . uniqid('exif_', true) . '.jpg';

        // Invalid date string triggers exception path -> null
        ExifReadDataStub::set($path, ['DateTimeOriginal' => 'not a date']);

        $file     = new SplFileInfo($path);
        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

        self::assertNull($strategy->generateFilename($file));
    }

    /**
     * Provides test cases for EXIF date handling.
     *
     * @return array<string, array{dateTimeOriginal: string, subSecTimeOriginal: ?string, pattern: string, extension: string, expected: string, description: string}>
     */
    public static function exifDateProvider(): array
    {
        return [
            'basic date format' => [
                'dateTimeOriginal'   => '2024:01:15 14:30:45',
                'subSecTimeOriginal' => null,
                'pattern'            => 'Y-m-d_H-i-s',
                'extension'          => 'jpg',
                'expected'           => '2024-01-15_14-30-45.jpg',
                'description'        => 'Should format basic EXIF date correctly',
            ],
            'with milliseconds' => [
                'dateTimeOriginal'   => '2024:01:15 14:30:45',
                'subSecTimeOriginal' => '123',
                'pattern'            => 'Y-m-d_H-i-s-v',
                'extension'          => 'jpg',
                'expected'           => '2024-01-15_14-30-45-123.jpg',
                'description'        => 'Should include milliseconds when present',
            ],
            'with microseconds' => [
                'dateTimeOriginal'   => '2024:01:15 14:30:45',
                'subSecTimeOriginal' => '123456',
                'pattern'            => 'Y-m-d_H-i-s-u',
                'extension'          => 'jpg',
                'expected'           => '2024-01-15_14-30-45-123456.jpg',
                'description'        => 'Should include microseconds when present',
            ],
            'custom format pattern' => [
                'dateTimeOriginal'   => '2024:03:20 09:15:30',
                'subSecTimeOriginal' => null,
                'pattern'            => 'Ymd-His',
                'extension'          => 'png',
                'expected'           => '20240320-091530.png',
                'description'        => 'Should apply custom date format pattern',
            ],
            'year only format' => [
                'dateTimeOriginal'   => '2024:12:25 23:59:59',
                'subSecTimeOriginal' => null,
                'pattern'            => 'Y',
                'extension'          => 'jpeg',
                'expected'           => '2024.jpeg',
                'description'        => 'Should handle year-only format',
            ],
            'month and day format' => [
                'dateTimeOriginal'   => '2024:07:04 12:00:00',
                'subSecTimeOriginal' => null,
                'pattern'            => 'F-j-Y',
                'extension'          => 'jpg',
                'expected'           => 'July-4-2024.jpg',
                'description'        => 'Should format month name and day correctly',
            ],
        ];
    }
}
}
