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
 * Verifies the {@see TemporalMetadata} value object which encapsulates
 * file-specific time and identity information.
 *
 * This test ensures that:
 * - Capture dates and Live Photo IDs are stored and retrieved correctly.
 * - Precision/ambiguity flags (fallback, ambiguous timezone) are handled.
 * - Live Photo heuristic fields (camera model, GPS, etc.) are correctly mapped.
 * - Case-insensitive normalization of Live Photo IDs works as expected.
 */
#[CoversClass(TemporalMetadata::class)]
final class TemporalMetadataTest extends TestCase
{
    /**
     * Replaces only the capture timestamp of fully populated synthetic metadata.
     * Raw QuickTime provenance, pairing markers, quality flags, device signals,
     * location and duration must remain intact, and the original stays unchanged.
     *
     * @return void
     */
    #[Test]
    public function captureDateCopyPreservesEveryIndependentMetadataField(): void
    {
        $capture  = new DateTimeImmutable('2024-01-15T10:30:00.123456+00:00');
        $raw      = new DateTimeImmutable('2024-01-15T09:00:00.654321+00:00');
        $updated  = new DateTimeImmutable('2024-01-15T11:30:00.123456+01:00');
        $original = new TemporalMetadata(
            $capture,
            ' SYNTHETIC-ID ',
            true,
            true,
            8192,
            ' Synthetic Make ',
            ' Test Model ',
            ' Test Software ',
            0.25,
            -0.5,
            2.6,
            true,
            $raw,
        );
        $copy = $original->withCaptureDateTime($updated);

        self::assertNotSame($original, $copy);
        self::assertSame($updated, $copy->getCaptureDateTime());
        self::assertSame($capture, $original->getCaptureDateTime());
        self::assertSame($raw, $copy->getRawQuickTimeCreateDate());
        self::assertSame($raw, $original->getRawQuickTimeCreateDate());
        self::assertSame($original->getLivePhotoId(), $copy->getLivePhotoId());
        self::assertSame($original->isFallbackDateTime(), $copy->isFallbackDateTime());
        self::assertSame($original->isAmbiguousTimezone(), $copy->isAmbiguousTimezone());
        self::assertSame($original->getLivePhotoVideoIndex(), $copy->getLivePhotoVideoIndex());
        self::assertSame($original->getCameraMake(), $copy->getCameraMake());
        self::assertSame($original->getCameraModel(), $copy->getCameraModel());
        self::assertSame($original->getSoftware(), $copy->getSoftware());
        self::assertSame($original->getLatitude(), $copy->getLatitude());
        self::assertSame($original->getLongitude(), $copy->getLongitude());
        self::assertSame($original->getVideoDurationSeconds(), $copy->getVideoDurationSeconds());
        self::assertSame($original->hasQuickTimeLivePhotoMarker(), $copy->hasQuickTimeLivePhotoMarker());
        self::assertSame('synthetic-id', $copy->getNormalizedLivePhotoId());
        self::assertSame('synthetic make|test model|test software', $copy->getNormalizedDeviceKey());
        self::assertTrue($copy->hasStillLivePhotoMarker());
        self::assertTrue($copy->hasVideoLivePhotoMarker());
    }

    /**
     * Ensures the 'isFallbackDateTime' flag defaults to false.
     *
     * Fallback dates (e.g., from file system mtime) are visually distinguished
     * in CLI output, so it's important that this flag is only true when
     * explicitly requested during extraction.
     */
    #[Test]
    public function itDefaultsIsFallbackDateTimeToFalse(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-15 10:30:00'),
            null,
        );

        self::assertFalse($metadata->isFallbackDateTime());
    }

    /**
     * Verifies that the 'isFallbackDateTime' flag is correctly preserved
     * when passed via the constructor.
     */
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

    /**
     * Ensures the fallback flag remains false when explicitly set as such
     * in the constructor.
     */
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

    /**
     * Checks if the capture date (CaptureDateTime) is correctly returned.
     */
    #[Test]
    public function itReturnsCaptureDateTime(): void
    {
        $dateTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $metadata = new TemporalMetadata($dateTime, null);

        self::assertSame($dateTime, $metadata->getCaptureDateTime());
    }

    /**
     * Ensures that null is returned when no capture date is set.
     */
    #[Test]
    public function itReturnsNullCaptureDateTimeWhenAbsent(): void
    {
        $metadata = new TemporalMetadata(null, 'live-photo-id');

        self::assertNull($metadata->getCaptureDateTime());
    }

    /**
     * Checks the correct return of the Live Photo ID.
     */
    #[Test]
    public function itReturnsLivePhotoId(): void
    {
        $metadata = new TemporalMetadata(null, 'ABC-123');

        self::assertSame('ABC-123', $metadata->getLivePhotoId());
    }

    /**
     * Ensures that timezone ambiguity is set to false by default.
     * Ambiguity occurs when a timestamp (e.g., from MP4) cannot be
     * uniquely assigned to a timezone (UTC vs. local time).
     */
    #[Test]
    public function itDefaultsIsAmbiguousTimezoneToFalse(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-15 10:30:00'),
            null,
        );

        self::assertFalse($metadata->isAmbiguousTimezone());
    }

    /**
     * Verifies that the flag for ambiguous timezones is correctly detected.
     */
    #[Test]
    public function itReturnsTrueWhenAmbiguousTimezoneIsSet(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-15 10:30:00'),
            null,
            false,
            true,
        );

        self::assertTrue($metadata->isAmbiguousTimezone());
    }

    /**
     * Checks all fields relevant for the Live Photo heuristic.
     * Validates ID normalization and device identity checks
     * (camera manufacturer, model, software version).
     */
    #[Test]
    public function itExposesAdditionalLivePhotoHeuristicFields(): void
    {
        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-15 10:30:00'),
            ' LivePhoto-ID ',
            false,
            false,
            8192,
            'Apple',
            'iPhone 8',
            '13.6.1',
            51.79375,
            10.60537,
            2.6,
            true,
        );

        self::assertSame(8192, $metadata->getLivePhotoVideoIndex());
        self::assertSame('Apple', $metadata->getCameraMake());
        self::assertSame('iPhone 8', $metadata->getCameraModel());
        self::assertSame('13.6.1', $metadata->getSoftware());
        self::assertSame(51.79375, $metadata->getLatitude());
        self::assertSame(10.60537, $metadata->getLongitude());
        self::assertSame(2.6, $metadata->getVideoDurationSeconds());
        self::assertTrue($metadata->hasQuickTimeLivePhotoMarker());
        self::assertTrue($metadata->hasStillLivePhotoMarker());
        self::assertTrue($metadata->hasVideoLivePhotoMarker());
        self::assertSame('livephoto-id', $metadata->getNormalizedLivePhotoId());
        self::assertSame('apple|iphone 8|13.6.1', $metadata->getNormalizedDeviceKey());
        self::assertTrue($metadata->hasComparableDeviceIdentity());
    }
}
