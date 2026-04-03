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
use MagicSunday\Renamer\Helper\DateDriftCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the extracted day-drift calculator that centralizes shared drift
 * semantics for output warnings and metadata validation flows.
 */
#[CoversClass(DateDriftCalculator::class)]
final class DateDriftCalculatorTest extends TestCase
{
    /**
     * Verifies drift between two filename-derived dates.
     */
    #[Test]
    public function computeDateDriftReturnsDaysBetweenFilenameDates(): void
    {
        self::assertSame(0, DateDriftCalculator::computeDateDrift('2024-01-15_photo.jpg', '2024-01-15_10-00-00.jpg'));
        self::assertSame(65, DateDriftCalculator::computeDateDrift('2024-01-15_photo.jpg', '2024-03-20_10-00-00.jpg'));
    }

    /**
     * Ensures drift stays undefined when one side has no parseable date.
     */
    #[Test]
    public function computeDateDriftReturnsNullWithoutDateInFilename(): void
    {
        self::assertNull(DateDriftCalculator::computeDateDrift('IMG_1234.jpg', '2024-03-20_10-00-00.jpg'));
        self::assertNull(DateDriftCalculator::computeDateDrift('2024-01-15_photo.jpg', 'IMG_1234.jpg'));
    }

    /**
     * Verifies drift between a filename date and metadata date using day-only semantics.
     */
    #[Test]
    public function computeDateDriftFromDateTimeReturnsDays(): void
    {
        $metadataDate = new DateTimeImmutable('2024-03-20 10:00:00');

        self::assertSame(65, DateDriftCalculator::computeDateDriftFromDateTime('2024-01-15_photo.jpg', $metadataDate));
        self::assertSame(0, DateDriftCalculator::computeDateDriftFromDateTime('2024-03-20_photo.jpg', $metadataDate));
        self::assertNull(DateDriftCalculator::computeDateDriftFromDateTime('IMG_1234.jpg', $metadataDate));
    }
}
