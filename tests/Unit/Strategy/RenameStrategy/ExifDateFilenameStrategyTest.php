<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use DateTimeImmutable;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sprintf;
use function uniqid;

/**
 * Verifies ExifDateFilenameStrategy, the primary rename strategy for the rename:exif
 * command. This strategy reads EXIF capture dates from image/video metadata and
 * formats them into standardised target filenames.
 *
 * Key guarantees verified here:
 * - Capture dates are formatted using the configured PHP date pattern
 * - Sub-second precision (milliseconds, microseconds) from the capture timestamp
 *   is correctly included in the filename
 * - Files without a capture date return null, allowing the caller to skip them
 * - Live Photo content identifiers are extracted and lowercased for companion pairing
 * - EXIF read failures are wrapped in TargetFilenameException for consistent error handling
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExifDateFilenameStrategy::class)]
final class ExifDateFilenameStrategyTest extends TestCase
{
    /**
     * Verifies that the strategy produces the expected target filename for various
     * capture dates and precision levels (seconds, milliseconds, microseconds).
     *
     * Each data provider case exercises a different date format pattern and extension
     * to confirm that the DateTime formatting, sub-second handling, and extension
     * preservation work correctly across the supported range.
     */
    #[Test]
    #[DataProvider('captureDateProvider')]
    public function itGeneratesFilenameFromCaptureDate(
        string $captureDateTime,
        string $pattern,
        string $extension,
        string $expected,
        string $description,
    ): void {
        $path = '/virtual/' . uniqid('capture_', true) . '.' . $extension;

        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable($captureDateTime), null),
        );

        $strategy = $this->createStrategy($pattern, $metadataExtractor);

        self::assertSame(
            $expected,
            $strategy->generateFilename(new SplFileInfo($path)),
            sprintf('Failed for case: %s', $description),
        );
    }

    /**
     * Verifies that generateFilename() returns null when the metadata extractor
     * provides no capture date for a file.
     *
     * Returning null signals to the grouping pipeline that this file cannot be
     * renamed by date and should be skipped. Without this, files lacking EXIF
     * data (e.g. screenshots, downloaded images) would produce invalid filenames
     * or throw uncaught exceptions.
     */
    #[Test]
    public function itReturnsNullWhenNoCaptureDate(): void
    {
        $path = '/virtual/' . uniqid('missing_', true) . '.jpg';

        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new TemporalMetadata(null, null));

        $strategy = $this->createStrategy('Y-m-d_H-i-s', $metadataExtractor);

        self::assertNull($strategy->generateFilename(new SplFileInfo($path)));
    }

    /**
     * Verifies that getLivePhotoContentIdentifier() extracts and lowercases the
     * content identifier from TemporalMetadata, even when no capture date exists.
     *
     * The content identifier (typically an Apple UUID) is the primary key for
     * Live Photo companion pairing. Lowercasing ensures case-insensitive matching
     * between the still image and its video counterpart, which may store the UUID
     * in different cases depending on the extraction tool.
     */
    #[Test]
    public function itExtractsLivePhotoContentIdentifierFromTemporalMetadata(): void
    {
        $path = '/virtual/' . uniqid('live_', true) . '.jpg';

        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(null, 'EXIF-UUID'),
        );

        $strategy = $this->createStrategy('Y-m-d_H-i-s', $metadataExtractor);

        self::assertSame('exif-uuid', $strategy->getLivePhotoContentIdentifier(new SplFileInfo($path)));
    }

    /**
     * Verifies that an ExifMetadataReadException thrown by the metadata extractor
     * is caught and re-thrown as a TargetFilenameException with the original message.
     *
     * This wrapping allows the grouping pipeline to handle all filename-generation
     * errors uniformly via TargetFilenameException, logging a warning and skipping
     * the file rather than aborting the entire batch.
     */
    #[Test]
    public function exifReadFailureIsConvertedToTargetFilenameException(): void
    {
        $path = '/virtual/' . uniqid('exif_failure_', true) . '.jpg';

        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new ExifMetadataReadException('metadata failure'));

        $strategy = $this->createStrategy('Y-m-d_H-i-s', $metadataExtractor);

        $this->expectException(TargetFilenameException::class);
        $this->expectExceptionMessage('metadata failure');

        $strategy->generateFilename(new SplFileInfo($path));
    }

    /**
     * Verifies that sub-second values in the capture timestamp are formatted
     * correctly as microseconds in the generated filename.
     *
     * The DateTimeImmutable from the metadata extractor already carries the full
     * microsecond precision, so the strategy simply formats it with the 'u' pattern.
     */
    #[Test]
    #[DataProvider('subSecondProvider')]
    public function itFormatsSubSecondValuesAsMicroseconds(
        string $captureDateTime,
        string $expectedMicroseconds,
    ): void {
        $path = '/virtual/' . uniqid('subsec_', true) . '.jpg';

        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable($captureDateTime), null),
        );

        $strategy = $this->createStrategy('Y-m-d_H-i-s-u', $metadataExtractor);

        self::assertSame(
            '2024-05-05_12-34-56-' . $expectedMicroseconds . '.jpg',
            $strategy->generateFilename(new SplFileInfo($path)),
        );
    }

    private function createStrategy(
        string $pattern,
        StubMetadataExtractor $metadataExtractor,
    ): ExifDateFilenameStrategy {
        $provider = new ExifMetadataProvider($metadataExtractor);

        return new ExifDateFilenameStrategy($pattern, $provider);
    }

    /**
     * @return array<string, array{captureDateTime: string, expectedMicroseconds: string}>
     */
    public static function subSecondProvider(): array
    {
        return [
            'milliseconds (3 digits)' => [
                'captureDateTime'      => '2024-05-05T12:34:56.123+00:00',
                'expectedMicroseconds' => '123000',
            ],
            'microseconds (6 digits)' => [
                'captureDateTime'      => '2024-05-05T12:34:56.123456+00:00',
                'expectedMicroseconds' => '123456',
            ],
            'no sub-seconds' => [
                'captureDateTime'      => '2024-05-05T12:34:56+00:00',
                'expectedMicroseconds' => '000000',
            ],
        ];
    }

    /**
     * @return array<string, array{captureDateTime: string, pattern: string, extension: string, expected: string, description: string}>
     */
    public static function captureDateProvider(): array
    {
        return [
            'basic timestamp' => [
                'captureDateTime' => '2024-05-05T12:34:56+00:00',
                'pattern'         => 'Y-m-d_H-i-s',
                'extension'       => 'jpg',
                'expected'        => '2024-05-05_12-34-56.jpg',
                'description'     => 'Formats the timestamp using second precision',
            ],
            'millisecond precision' => [
                'captureDateTime' => '2024-05-05T12:34:56.123+00:00',
                'pattern'         => 'Y-m-d_H-i-s-v',
                'extension'       => 'jpeg',
                'expected'        => '2024-05-05_12-34-56-123.jpg',
                'description'     => 'Appends millisecond precision from capture time',
            ],
            'microsecond precision' => [
                'captureDateTime' => '2024-05-05T12:34:56.123456+00:00',
                'pattern'         => 'Y-m-d_H-i-s-u',
                'extension'       => 'png',
                'expected'        => '2024-05-05_12-34-56-123456.png',
                'description'     => 'Formats full microsecond precision directly from DateTimeInterface',
            ],
        ];
    }
}
