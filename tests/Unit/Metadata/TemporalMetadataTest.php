<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Metadata;

use DateTimeImmutable;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the immutable value object contract of TemporalMetadata,
 * including the fallback DateTime flag.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TemporalMetadata::class)]
final class TemporalMetadataTest extends TestCase
{
    #[Test]
    public function itDefaultsIsFallbackDateTimeToFalse(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-15 10:30:00'),
            null,
        );

        self::assertFalse($metadata->isFallbackDateTime());
    }

    #[Test]
    public function itReturnsTrueWhenFallbackFlagIsSet(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-15 10:30:00'),
            null,
            true,
        );

        self::assertTrue($metadata->isFallbackDateTime());
    }

    #[Test]
    public function itReturnsFalseWhenFallbackFlagIsExplicitlyFalse(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-15 10:30:00'),
            'live-photo-id',
            false,
        );

        self::assertFalse($metadata->isFallbackDateTime());
    }

    #[Test]
    public function itReturnsCaptureDateTime(): void
    {
        $dateTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $metadata = new TemporalMetadata($dateTime, null);

        self::assertSame($dateTime, $metadata->getCaptureDateTime());
    }

    #[Test]
    public function itReturnsNullCaptureDateTimeWhenAbsent(): void
    {
        $metadata = new TemporalMetadata(null, 'live-photo-id');

        self::assertNull($metadata->getCaptureDateTime());
    }

    #[Test]
    public function itReturnsLivePhotoId(): void
    {
        $metadata = new TemporalMetadata(null, 'ABC-123');

        self::assertSame('ABC-123', $metadata->getLivePhotoId());
    }
}
