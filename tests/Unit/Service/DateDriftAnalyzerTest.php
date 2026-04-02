<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use DateTimeImmutable;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the shared drift analyzer that keeps filename-versus-metadata drift
 * calculations consistent between verify and write-date command paths.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DateDriftAnalyzer::class)]
#[UsesClass(FileHelper::class)]
final class DateDriftAnalyzerTest extends TestCase
{
    /**
     * Verifies that drift between two explicit date values is returned as an
     * absolute day count, independent of the argument order.
     */
    #[Test]
    public function calculateDateDriftInDaysReturnsAbsoluteDayDifference(): void
    {
        $analyzer = new DateDriftAnalyzer();

        self::assertSame(
            7,
            $analyzer->calculateDateDriftInDays(
                new DateTimeImmutable('2024-01-15 10:00:00'),
                new DateTimeImmutable('2024-01-22 10:00:00'),
            ),
        );
        self::assertSame(
            7,
            $analyzer->calculateDateDriftInDays(
                new DateTimeImmutable('2024-01-22 10:00:00'),
                new DateTimeImmutable('2024-01-15 10:00:00'),
            ),
        );
    }

    /**
     * Verifies that filename-based drift is calculated from the parsed
     * date-based pathname when such a date exists.
     */
    #[Test]
    public function calculateFilenameDateDriftInDaysUsesParsedFilenameDate(): void
    {
        $analyzer = new DateDriftAnalyzer();
        $file     = new SplFileInfo('/tmp/2024-01-15_10-00-00.jpg');

        self::assertSame(
            3,
            $analyzer->calculateFilenameDateDriftInDays(
                $file,
                new DateTimeImmutable('2024-01-18 22:00:00'),
            ),
        );
    }

    /**
     * Verifies that files without a date-based pathname return null so callers
     * can keep their existing "not comparable" behavior.
     */
    #[Test]
    public function calculateFilenameDateDriftInDaysReturnsNullWithoutDateInFilename(): void
    {
        $analyzer = new DateDriftAnalyzer();
        $file     = new SplFileInfo('/tmp/IMG_1234.jpg');

        self::assertNull(
            $analyzer->calculateFilenameDateDriftInDays(
                $file,
                new DateTimeImmutable('2024-01-18 22:00:00'),
            ),
        );
    }
}
