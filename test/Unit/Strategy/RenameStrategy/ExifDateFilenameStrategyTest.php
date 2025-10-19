<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * EXIF Function Stubbing for Testing.
 *
 * IMPORTANT: We define a namespaced function stub for exif_read_data in the
 * same namespace as the class under test. Because the class refers to
 * exif_read_data without a leading backslash, PHP's namespaced function
 * resolution will prefer this function over the global one. This allows us to
 * provide deterministic EXIF data without relying on the PHP EXIF extension or
 * crafting binary EXIF segments.
 *
 * This technique is crucial for:
 * - Testing without requiring actual image files with EXIF data
 * - Providing predictable test data regardless of system configuration
 * - Testing edge cases and error conditions that would be hard to reproduce with real files
 * - Ensuring tests run even when the EXIF extension is not installed
 */

namespace MagicSunday\Renamer\Strategy\RenameStrategy {
    /**
     * Internal test stub state holder for EXIF responses.
     *
     * This class maintains a mapping between file paths and their simulated EXIF data.
     * It allows tests to configure specific EXIF responses for specific files,
     * enabling comprehensive testing of the ExifDateFilenameStrategy.
     */
    final class ExifReadDataStub
    {
        /**
         * @var array<string, array|false> Map of file paths to EXIF data or false for no EXIF
         */
        public static array $map = [];

        /**
         * Sets the EXIF data that should be returned for a specific file path.
         *
         * @param string      $path The file path to configure
         * @param array|false $data The EXIF data array or false to simulate no EXIF data
         *
         * @return void
         */
        public static function set(string $path, array|false $data): void
        {
            self::$map[$path] = $data;
        }

        /**
         * Resets the stub map, clearing all configured EXIF data.
         *
         * This should be called in tearDown() to ensure test isolation.
         *
         * @return void
         */
        public static function reset(): void
        {
            self::$map = [];
        }
    }

    /**
     * Internal test stub state holder for file_get_contents responses.
     */
    final class FileGetContentsStub
    {
        /**
         * @var array<string, string|false>
         */
        public static array $map = [];

        public static function set(string $path, string|false $data): void
        {
            self::$map[$path] = $data;
        }

        public static function reset(): void
        {
            self::$map = [];
        }
    }

    /**
     * Stub replacement for the global exif_read_data function.
     *
     * This function is called by ExifDateFilenameStrategy::getExifData() instead of
     * the global PHP function due to namespace resolution rules. It returns
     * pre-configured test data based on the file path.
     *
     * @param string $filename The path to the "image" file
     *
     * @return array|false The configured EXIF data or false if not configured
     */
    function exif_read_data(string $filename): array|false
    {
        return ExifReadDataStub::$map[$filename] ?? false;
    }

    /**
     * Stub replacement for the global file_get_contents function.
     *
     * @param string $filename The path to the file
     *
     * @return string|false The configured file contents or the actual file contents
     */
    function file_get_contents(string $filename): string|false
    {
        if (array_key_exists($filename, FileGetContentsStub::$map)) {
            return FileGetContentsStub::$map[$filename];
        }

        return \file_get_contents($filename);
    }
}

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy {
    use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
    use MagicSunday\Renamer\Strategy\RenameStrategy\ExifReadDataStub;
    use MagicSunday\Renamer\Strategy\RenameStrategy\FileGetContentsStub;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;
    use SplFileInfo;

    use function sprintf;
    use function uniqid;

    /**
     * Unit tests for ExifDateFilenameStrategy class.
     *
     * This test class validates the behavior of ExifDateFilenameStrategy, which generates
     * filenames based on EXIF date information embedded in image files. This strategy is
     * particularly useful for organizing photo collections by their actual capture date
     * rather than file modification dates.
     *
     * Key features tested:
     * - EXIF DateTimeOriginal extraction and formatting
     * - Support for subsecond precision (milliseconds/microseconds)
     * - Custom date format patterns using PHP's DateTime format syntax
     * - Graceful handling of missing or invalid EXIF data
     * - Error recovery for malformed date strings
     *
     * Common use cases:
     * - Organizing photos chronologically (e.g., "2024-01-15_14-30-45.jpg")
     * - Creating consistent naming for photos from multiple cameras
     * - Preserving original capture time when copying/moving files
     * - Batch renaming photo collections with date-based names
     *
     * The test uses a stubbed exif_read_data function to provide deterministic
     * test data without requiring actual image files or the PHP EXIF extension.
     *
     * @author  Rico Sonntag <mail@ricosonntag.de>
     * @license https://opensource.org/licenses/MIT
     * @link    https://github.com/magicsunday/photo-renamer/
     */
    #[CoversClass(ExifDateFilenameStrategy::class)]
    class ExifDateFilenameStrategyTest extends TestCase
    {
        /**
         * Cleans up after each test method.
         *
         * Resets the EXIF data stub to ensure test isolation. This prevents
         * EXIF data configured in one test from affecting subsequent tests.
         *
         * @return void
         */
        protected function tearDown(): void
        {
            // Ensure the stub maps are reset after each test
            ExifReadDataStub::reset();
            FileGetContentsStub::reset();
        }

        /**
         * Tests that filenames are correctly generated from EXIF date information.
         *
         * This parameterized test validates the core functionality of extracting
         * and formatting EXIF DateTimeOriginal values into filenames. It covers
         * various date format patterns and subsecond precision options.
         *
         * Test scenarios include:
         * - Basic date/time formatting (Y-m-d_H-i-s)
         * - Millisecond precision with SubSecTimeOriginal
         * - Microsecond precision for high-speed photography
         * - Custom format patterns for different naming conventions
         * - Year-only or month-day combinations
         * - Human-readable month names
         *
         * The EXIF date format "YYYY:MM:DD HH:MM:SS" is converted to the
         * specified output format using PHP's DateTime formatting.
         *
         * @param string      $dateTimeOriginal   The EXIF DateTimeOriginal value (YYYY:MM:DD HH:MM:SS format)
         * @param string|null $subSecTimeOriginal Optional subsecond precision data
         * @param string      $pattern            The DateTime format pattern for the filename
         * @param string      $extension          The file extension to preserve
         * @param string      $expected           The expected generated filename
         * @param string      $description        Human-readable description of the test case
         *
         * @return void
         */
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
            // Generate a unique virtual path for this test
            $path = '/virtual/' . uniqid('exif_', true) . '.' . $extension;

            // Configure the EXIF stub with test data
            $exif = [
                'DateTimeOriginal' => $dateTimeOriginal,
            ];

            // Add subsecond data if provided
            if ($subSecTimeOriginal !== null) {
                $exif['SubSecTimeOriginal'] = $subSecTimeOriginal;
            }

            // Register the EXIF data for this file path
            ExifReadDataStub::set($path, $exif);

            // Create file info and strategy instances
            $file     = new SplFileInfo($path);
            $strategy = new ExifDateFilenameStrategy($pattern);

            // Assert the generated filename matches expectations
            self::assertSame(
                $expected,
                $strategy->generateFilename($file),
                sprintf('Failed for case: %s', $description)
            );
        }

        #[Test]
        public function itExtractsLivePhotoContentIdentifierFromExifData(): void
        {
            $path = '/virtual/' . uniqid('live_', true) . '.jpg';

            ExifReadDataStub::set($path, [
                'DateTimeOriginal' => '2024:05:05 12:00:00',
                'ContentIdentifier' => 'A1B2C3D4-EXIF-UUID',
            ]);

            $file = new SplFileInfo($path);
            $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

            // Trigger metadata extraction to populate the cache
            $strategy->generateFilename($file);

            self::assertSame('A1B2C3D4-EXIF-UUID', $strategy->getLivePhotoContentIdentifier($file));
        }

        #[Test]
        public function itExtractsLivePhotoContentIdentifierFromQuickTimeMetadata(): void
        {
            $path = '/virtual/' . uniqid('quicktime_', true) . '.mov';

            ExifReadDataStub::set($path, false);
            FileGetContentsStub::set($path, self::createQuickTimeSample('550E8400-E29B-41D4-A716-446655440000'));

            $file = new SplFileInfo($path);
            $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

            self::assertSame(
                '550E8400-E29B-41D4-A716-446655440000',
                $strategy->getLivePhotoContentIdentifier($file),
            );
        }

        private static function createQuickTimeSample(string $identifier): string
        {
            $key = 'com.apple.quicktime.content.identifier';

            $keyEntryPayload = pack('N', 8 + strlen($key))
                . "\0\0\0\0"
                . $key;

            $keysPayload = "\0\0\0\0"
                . pack('N', 1)
                . $keyEntryPayload;

            $keysAtom = pack('N', 8 + strlen($keysPayload))
                . 'keys'
                . $keysPayload;

            $dataPayload = pack('N', 16 + strlen($identifier))
                . 'data'
                . "\0\0\0\1"
                . "\0\0\0\0"
                . $identifier;

            $ilstEntry = pack('N', 8 + strlen($dataPayload))
                . pack('N', 1)
                . $dataPayload;

            $ilstAtom = pack('N', 8 + strlen($ilstEntry))
                . 'ilst'
                . $ilstEntry;

            $metaPayload = "\0\0\0\0"
                . $keysAtom
                . $ilstAtom;

            $metaAtom = pack('N', 8 + strlen($metaPayload))
                . 'meta'
                . $metaPayload;

            $udtaAtom = pack('N', 8 + strlen($metaAtom))
                . 'udta'
                . $metaAtom;

            $moovAtom = pack('N', 8 + strlen($udtaAtom))
                . 'moov'
                . $udtaAtom;

            return $moovAtom;
        }

        /**
         * Tests that null is returned when no EXIF data is available.
         *
         * This test ensures the strategy gracefully handles files without EXIF data,
         * which can occur with:
         * - Non-image files accidentally processed
         * - Images without EXIF headers (e.g., screenshots, generated images)
         * - Corrupted EXIF data that cannot be read
         * - Files where EXIF has been stripped for privacy
         *
         * The strategy should return null to indicate it cannot generate a filename,
         * allowing fallback strategies to be used.
         *
         * @return void
         */
        #[Test]
        public function itReturnsNullWhenNoExifData(): void
        {
            // Create a unique test file path
            $path = '/virtual/' . uniqid('exif_', true) . '.jpg';

            // Configure stub to return false (no EXIF data)
            ExifReadDataStub::set($path, false);

            // Create file and strategy instances
            $file     = new SplFileInfo($path);
            $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

            // Assert null is returned when EXIF data is unavailable
            self::assertNull($strategy->generateFilename($file));
        }

        /**
         * Tests that null is returned when DateTimeOriginal is missing from EXIF.
         *
         * Some images may have EXIF data but lack the DateTimeOriginal field.
         * This can happen with:
         * - Images edited by software that strips certain EXIF fields
         * - Images with partial EXIF data
         * - Non-camera sources that don't set DateTimeOriginal
         *
         * The strategy should return null when the required date field is missing,
         * even if other EXIF data is present.
         *
         * @return void
         */
        #[Test]
        public function itReturnsNullWhenDateTimeOriginalMissing(): void
        {
            // Create a unique test file path
            $path = '/virtual/' . uniqid('exif_', true) . '.jpg';

            // Configure EXIF data without DateTimeOriginal
            ExifReadDataStub::set($path, ['SomeOtherTag' => 'value']);

            // Create file and strategy instances
            $file     = new SplFileInfo($path);
            $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

            // Assert null is returned when DateTimeOriginal is missing
            self::assertNull($strategy->generateFilename($file));
        }

        /**
         * Tests that null is returned when the date string is invalid.
         *
         * This test ensures proper error handling when EXIF contains malformed
         * date strings that cannot be parsed. This might occur with:
         * - Corrupted EXIF data
         * - Non-standard date formats from obscure cameras
         * - Manual EXIF editing with incorrect values
         *
         * The strategy should catch DateTime exceptions and return null
         * rather than propagating the error.
         *
         * @return void
         */
        #[Test]
        public function itReturnsNullOnInvalidDate(): void
        {
            // Create a unique test file path
            $path = '/virtual/' . uniqid('exif_', true) . '.jpg';

            // Configure EXIF with an invalid date string
            ExifReadDataStub::set($path, ['DateTimeOriginal' => 'not a date']);

            // Create file and strategy instances
            $file     = new SplFileInfo($path);
            $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s');

            // Assert null is returned for invalid date strings
            self::assertNull($strategy->generateFilename($file));
        }

        #[Test]
        public function itIgnoresNonStringSubSecondData(): void
        {
            $path = '/virtual/' . uniqid('exif_', true) . '.jpg';

            ExifReadDataStub::set($path, [
                'DateTimeOriginal'   => '2024:01:15 14:30:45',
                'SubSecTimeOriginal' => 123,
            ]);

            $file     = new SplFileInfo($path);
            $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s-u');

            self::assertSame('2024-01-15_14-30-45-000000.jpg', $strategy->generateFilename($file));
        }

        /**
         * Provides test cases for EXIF date handling.
         *
         * This data provider supplies various scenarios for testing EXIF date
         * extraction and formatting. Each case demonstrates different format
         * patterns and precision levels available in the strategy.
         *
         * Test cases cover:
         * - Standard date/time formats for photo organization
         * - Subsecond precision for burst mode or high-speed photography
         * - Custom patterns for specific workflow requirements
         * - Human-readable formats with month names
         * - Compact formats for space-constrained naming
         *
         * The date format in EXIF (YYYY:MM:DD HH:MM:SS) is consistently
         * transformed to the desired output format.
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
