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
use MagicSunday\Renamer\Helper\FilenameDateParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Verifies the dedicated filename-date parser that now owns the supported
 * rename timestamp patterns outside the generic `FileHelper` catch-all class.
 *
 * The tests focus on two responsibilities:
 * - supported filename conventions still resolve to the expected timestamp
 * - invalid calendar or clock values are rejected instead of being normalized
 */
#[CoversClass(FilenameDateParser::class)]
final class FilenameDateParserTest extends TestCase
{
    /**
     * Verifies that supported filename patterns are mapped to the expected timestamp.
     *
     * @param string      $path        Filename or path to analyze.
     * @param string|null $expected    Expected timestamp string or null.
     * @param string      $description Failure context.
     */
    #[Test]
    #[DataProvider('extractDateTimeFromPathProvider')]
    public function extractDateTimeFromPath(
        string $path,
        ?string $expected,
        string $description,
    ): void {
        $result = FilenameDateParser::extractDateTimeFromPath($path);

        if ($expected === null) {
            self::assertNull(
                $result,
                sprintf('Failed for case: %s (expected null)', $description),
            );

            return;
        }

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

    /**
     * Provides supported and unsupported parser cases.
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

    /**
     * Ensures invalid month values are rejected.
     */
    #[Test]
    public function extractDateTimeRejectsInvalidMonth(): void
    {
        self::assertNull(FilenameDateParser::extractDateTimeFromPath('2024-13-01.jpg'));
        self::assertNull(FilenameDateParser::extractDateTimeFromPath('2024-00-01.jpg'));
    }

    /**
     * Ensures invalid day values are rejected.
     */
    #[Test]
    public function extractDateTimeRejectsInvalidDay(): void
    {
        self::assertNull(FilenameDateParser::extractDateTimeFromPath('2024-02-30.jpg'));
        self::assertNull(FilenameDateParser::extractDateTimeFromPath('2024-01-32.jpg'));
    }

    /**
     * Ensures invalid time values are rejected.
     */
    #[Test]
    public function extractDateTimeRejectsInvalidTime(): void
    {
        self::assertNull(FilenameDateParser::extractDateTimeFromPath('2024-01-15_25-00-00.jpg'));
        self::assertNull(FilenameDateParser::extractDateTimeFromPath('2024-01-15_12-60-00.jpg'));
        self::assertNull(FilenameDateParser::extractDateTimeFromPath('2024-01-15_12-00-60.jpg'));
    }

    /**
     * Ensures boundary calendar and clock values remain valid.
     */
    #[Test]
    public function extractDateTimeAcceptsBoundaryValues(): void
    {
        $min = FilenameDateParser::extractDateTimeFromPath('2024-01-01_00-00-00.jpg');
        self::assertNotNull($min);
        self::assertSame('2024-01-01 00:00:00', $min->format('Y-m-d H:i:s'));

        $max = FilenameDateParser::extractDateTimeFromPath('2024-12-31_23-59-59.jpg');
        self::assertNotNull($max);
        self::assertSame('2024-12-31 23:59:59', $max->format('Y-m-d H:i:s'));
    }
}
