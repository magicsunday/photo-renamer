<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use DateTimeImmutable;
use MagicSunday\Renamer\Helper\FileHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sprintf;

/**
 * Unit tests for the static helper methods on the FileHelper class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(FileHelper::class)]
final class FileHelperTest extends TestCase
{
    /**
     * Tests basename extraction without extension for various file types.
     *
     * @param string $path        The file path to test
     * @param string $expected    The expected basename without extension
     * @param string $description Human-readable description of the test case
     */
    #[Test]
    #[DataProvider('basenameWithoutExtensionProvider')]
    public function basenameWithoutExtension(
        string $path,
        string $expected,
        string $description,
    ): void {
        $file = new SplFileInfo($path);

        self::assertSame(
            $expected,
            FileHelper::basenameWithoutExtension($file),
            sprintf('Failed for case: %s', $description),
        );
    }

    /**
     * Provides test cases for basenameWithoutExtension().
     *
     * @return array<string, array{path: string, expected: string, description: string}>
     */
    public static function basenameWithoutExtensionProvider(): array
    {
        return [
            'normal file with extension' => [
                'path'        => '/photos/IMG_1234.jpg',
                'expected'    => 'IMG_1234',
                'description' => 'Should strip the extension from a normal file',
            ],
            'file without extension' => [
                'path'        => '/photos/README',
                'expected'    => 'README',
                'description' => 'Should return the full basename when there is no extension',
            ],
            'dotfile' => [
                'path'        => '/photos/.hidden',
                'expected'    => '.hidden',
                'description' => 'Should return the full basename for a dotfile (leading dot is not an extension separator)',
            ],
        ];
    }

    /**
     * Tests duplicate suffix stripping from basenames.
     *
     * @param string $basename    The input basename without extension
     * @param string $expected    The expected result after stripping
     * @param string $description Human-readable description of the test case
     */
    #[Test]
    #[DataProvider('stripDuplicateSuffixProvider')]
    public function stripDuplicateSuffix(
        string $basename,
        string $expected,
        string $description,
    ): void {
        self::assertSame(
            $expected,
            FileHelper::stripDuplicateSuffix($basename),
            sprintf('Failed for case: %s', $description),
        );
    }

    /**
     * Provides test cases for stripDuplicateSuffix().
     *
     * @return array<string, array{basename: string, expected: string, description: string}>
     */
    public static function stripDuplicateSuffixProvider(): array
    {
        return [
            'no suffix present' => [
                'basename'    => 'IMG_1234',
                'expected'    => 'IMG_1234',
                'description' => 'Should return the basename unchanged when no duplicate suffix exists',
            ],
            'single suffix' => [
                'basename'    => 'IMG_1234-duplicate-001',
                'expected'    => 'IMG_1234',
                'description' => 'Should strip a single duplicate suffix',
            ],
            'nested suffix strips only last' => [
                'basename'    => 'IMG_1234-duplicate-001-duplicate-002',
                'expected'    => 'IMG_1234-duplicate-001',
                'description' => 'Should strip only the trailing duplicate suffix thanks to end-of-string anchor',
            ],
        ];
    }

    /**
     * Tests date+time extraction from filename paths.
     *
     * @param string      $path        The file path to test
     * @param string|null $expected    The expected date+time string (Y-m-d H:i:s), or null
     * @param string      $description Human-readable description of the test case
     */
    #[Test]
    #[DataProvider('extractDateTimeFromPathProvider')]
    public function extractDateTimeFromPath(
        string $path,
        ?string $expected,
        string $description,
    ): void {
        $result = FileHelper::extractDateTimeFromPath($path);

        if ($expected === null) {
            self::assertNull(
                $result,
                sprintf('Failed for case: %s (expected null)', $description),
            );
        } else {
            self::assertInstanceOf(
                DateTimeImmutable::class,
                $result,
                sprintf('Failed for case: %s (expected DateTimeImmutable)', $description),
            );

            self::assertSame(
                $expected,
                $result->format('Y-m-d H:i:s'),
                sprintf('Failed for case: %s', $description),
            );
        }
    }

    /**
     * Provides test cases for extractDateTimeFromPath().
     *
     * @return array<string, array{path: string, expected: string|null, description: string}>
     */
    public static function extractDateTimeFromPathProvider(): array
    {
        return [
            'date with time and milliseconds' => [
                'path'        => '/photos/2013-10-17_10-36-18-000.mp4',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time, ignoring milliseconds',
            ],
            'date with time' => [
                'path'        => '/photos/2013-10-17_10-36-18.mp4',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time from separator-style filename',
            ],
            'date only' => [
                'path'        => '/photos/2013-10-17.jpg',
                'expected'    => '2013-10-17 00:00:00',
                'description' => 'Should extract date only, with time set to midnight',
            ],
            'compact date and time' => [
                'path'        => '/photos/20131017_103618.jpg',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time from compact format',
            ],
            'prefixed compact date and time' => [
                'path'        => '/photos/IMG_20131017_103618.jpg',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time from IMG_ prefixed compact format',
            ],
            'no date in filename' => [
                'path'        => '/photos/IMG_1234.jpg',
                'expected'    => null,
                'description' => 'Should return null when no date pattern is found',
            ],
            'compact date only' => [
                'path'        => '/photos/20131017.jpg',
                'expected'    => '2013-10-17 00:00:00',
                'description' => 'Should extract compact date only, with time set to midnight',
            ],
            'date with numbered suffix' => [
                'path'        => '/photos/2019-06-12-01.jpg',
                'expected'    => '2019-06-12 00:00:00',
                'description' => 'Should extract date from filename with numeric suffix (not time)',
            ],
        ];
    }
}
