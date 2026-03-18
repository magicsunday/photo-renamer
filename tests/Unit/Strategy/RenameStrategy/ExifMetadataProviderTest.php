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
use DateTimeInterface;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies ExifMetadataProvider, the caching facade over MetadataExtractor
 * that provides direct access to capture timestamps and content identifiers.
 *
 * Key guarantees:
 * - Capture timestamps are returned as-is from TemporalMetadata (with microsecond precision)
 * - Content identifiers are normalised (lowercased, trimmed) for case-insensitive pairing
 * - Missing metadata returns null instead of throwing
 * - Extraction errors preserve the original ExifMetadataReadException type
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExifMetadataProvider::class)]
final class ExifMetadataProviderTest extends TestCase
{
    /**
     * Verifies that a TemporalMetadata with a capture date yields a DateTimeInterface
     * with the correct date and microsecond precision preserved.
     */
    #[Test]
    public function itReturnsCaptureDateTime(): void
    {
        $path              = '/tmp/sample.jpg';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable('2024-05-05T12:34:56.123+00:00'), null),
        );

        $provider = new ExifMetadataProvider($metadataExtractor);

        $captureDateTime = $provider->getCaptureDateTime(new SplFileInfo($path));

        self::assertInstanceOf(DateTimeInterface::class, $captureDateTime);
        self::assertSame('2024:05:05 12:34:56', $captureDateTime->format('Y:m:d H:i:s'));
        self::assertSame('123000', $captureDateTime->format('u'));
        self::assertNull($provider->getContentIdentifier(new SplFileInfo($path)));
    }

    /**
     * Verifies that both getCaptureDateTime() and getContentIdentifier() return null
     * when the metadata extractor has no response for the given file path.
     */
    #[Test]
    public function itReturnsNullWhenMetadataMissing(): void
    {
        $path              = '/tmp/missing.jpg';
        $metadataExtractor = new StubMetadataExtractor();

        $provider = new ExifMetadataProvider($metadataExtractor);

        self::assertNull($provider->getCaptureDateTime(new SplFileInfo($path)));
        self::assertNull($provider->getContentIdentifier(new SplFileInfo($path)));
    }

    /**
     * Verifies that a capture date with full microsecond precision (6 fractional
     * digits) is preserved in the returned DateTimeInterface.
     */
    #[Test]
    public function itPreservesMicrosecondPrecision(): void
    {
        $path              = '/tmp/video_micro.mov';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable('2024-05-05T12:34:56.123456+00:00'), null),
        );

        $provider = new ExifMetadataProvider($metadataExtractor);

        $captureDateTime = $provider->getCaptureDateTime(new SplFileInfo($path));

        self::assertInstanceOf(DateTimeInterface::class, $captureDateTime);
        self::assertSame('2024:05:05 12:34:56', $captureDateTime->format('Y:m:d H:i:s'));
        self::assertSame('123456', $captureDateTime->format('u'));
    }

    /**
     * Verifies that the Live Photo content identifier is extracted and lowercased
     * even when the capture date is absent.
     *
     * MOV companions in Live Photos often have no EXIF date but always carry the
     * Apple content identifier. This test ensures they are still discoverable
     * by the pairing service without requiring a valid capture date.
     */
    #[Test]
    public function itExtractsLivePhotoIdWhenCaptureDateMissing(): void
    {
        $path              = '/tmp/live.mov';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new TemporalMetadata(null, 'UUID-5678'));

        $provider = new ExifMetadataProvider($metadataExtractor);

        self::assertNull($provider->getCaptureDateTime(new SplFileInfo($path)));

        $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

        self::assertSame('uuid-5678', $identifier);
    }

    /**
     * Verifies that the content identifier is lowercased and whitespace-trimmed.
     *
     * Different extraction tools may produce identifiers with varying case and
     * leading/trailing whitespace. Normalisation ensures that the still image
     * and its video companion always match.
     */
    #[Test]
    public function itNormalisesLivePhotoIdentifierCasing(): void
    {
        $path              = '/tmp/live-photo.jpg';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new TemporalMetadata(null, '  LivePhoto-ID '));

        $provider = new ExifMetadataProvider($metadataExtractor);

        $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

        self::assertSame('livephoto-id', $identifier);
    }

    /**
     * Verifies that an ExifMetadataReadException from the extractor is re-thrown
     * as-is, preserving its specific type for callers that distinguish metadata
     * read failures from other TargetFilenameException subtypes.
     */
    #[Test]
    public function itPreservesExifMetadataReadExceptionType(): void
    {
        $path              = '/tmp/error.jpg';
        $original          = new ExifMetadataReadException('failure');
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, $original);

        $provider = new ExifMetadataProvider($metadataExtractor);

        $this->expectException(ExifMetadataReadException::class);
        $this->expectExceptionMessage('failure');

        $provider->getCaptureDateTime(new SplFileInfo($path));
    }
}
