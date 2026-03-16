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
use MagicSunday\Renamer\Metadata\ContentIdentifier;
use MagicSunday\Renamer\Metadata\ExifData;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies ExifMetadataProvider, the adapter that converts raw TemporalMetadata
 * from the metadata extractor into the ExifData and ContentIdentifier value
 * objects consumed by ExifDateFilenameStrategy.
 *
 * Key guarantees:
 * - Capture dates are split into DateTimeOriginal and SubSecTimeOriginal
 * - Microsecond precision is preserved when the capture date includes fractional seconds
 * - Content identifiers are normalised (lowercased, trimmed) for case-insensitive pairing
 * - Missing metadata returns null instead of throwing
 * - Extraction errors are wrapped in TargetFilenameException with the original cause
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExifMetadataProvider::class)]
final class ExifMetadataProviderTest extends TestCase
{
    /**
     * Verifies that a TemporalMetadata with a capture date is converted into an
     * ExifData instance with the date in EXIF format ("YYYY:MM:DD HH:MM:SS"),
     * sub-second digits extracted, and content identifier left null.
     *
     * This is the happy-path conversion that feeds ExifDateFilenameStrategy
     * with the components needed to build the target filename.
     */
    #[Test]
    public function itReturnsExifDataWhenMetadataAvailable(): void
    {
        $path              = '/tmp/sample.jpg';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable('2024-05-05T12:34:56.123+00:00'), null),
        );

        $provider = new ExifMetadataProvider($metadataExtractor);

        $exifData = $provider->getExifData(new SplFileInfo($path));

        self::assertInstanceOf(ExifData::class, $exifData);
        self::assertSame('2024:05:05 12:34:56', $exifData->getDateTimeOriginal());
        self::assertSame('123', $exifData->getSubSecTimeOriginal());
        self::assertNull($exifData->getContentIdentifier());
    }

    /**
     * Verifies that both getExifData() and getContentIdentifier() return null
     * when the metadata extractor has no response for the given file path.
     *
     * This covers files that lack EXIF data entirely (e.g. plain text, screenshots).
     * Returning null allows the caller to skip the file gracefully rather than
     * crashing the batch.
     */
    #[Test]
    public function itReturnsNullWhenMetadataMissing(): void
    {
        $path              = '/tmp/missing.jpg';
        $metadataExtractor = new StubMetadataExtractor();

        $provider = new ExifMetadataProvider($metadataExtractor);

        self::assertNull($provider->getExifData(new SplFileInfo($path)));
        self::assertNull($provider->getContentIdentifier(new SplFileInfo($path)));
    }

    /**
     * Verifies that a capture date with full microsecond precision (6 fractional
     * digits) is correctly split: the integer seconds go into DateTimeOriginal and
     * all 6 sub-second digits go into SubSecTimeOriginal.
     *
     * This ensures that video files (which typically store creation dates with
     * higher precision than still images) produce filenames that reflect their
     * exact capture moment, avoiding false collisions within the same second.
     */
    #[Test]
    public function itNormalisesCaptureDateMicroseconds(): void
    {
        $path              = '/tmp/video_micro.mov';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $path,
            new TemporalMetadata(new DateTimeImmutable('2024-05-05T12:34:56.123456+00:00'), null),
        );

        $provider = new ExifMetadataProvider($metadataExtractor);

        $exifData = $provider->getExifData(new SplFileInfo($path));

        self::assertInstanceOf(ExifData::class, $exifData);
        self::assertSame('2024:05:05 12:34:56', $exifData->getDateTimeOriginal());
        self::assertSame('123456', $exifData->getSubSecTimeOriginal());
    }

    /**
     * Verifies that the Live Photo content identifier is extracted and lowercased
     * even when the capture date is absent, and that getExifData() returns null
     * in this case.
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

        self::assertNull($provider->getExifData(new SplFileInfo($path)));

        $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

        self::assertInstanceOf(ContentIdentifier::class, $identifier);
        self::assertSame('uuid-5678', $identifier->getValue());
    }

    /**
     * Verifies that the content identifier is lowercased and whitespace-trimmed
     * before being stored in the ContentIdentifier value object.
     *
     * Different extraction tools may produce identifiers with varying case and
     * leading/trailing whitespace. Normalisation ensures that the still image
     * and its video companion always match, regardless of how their metadata
     * was written.
     */
    #[Test]
    public function itNormalisesLivePhotoIdentifierCasing(): void
    {
        $path              = '/tmp/live-photo.jpg';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new TemporalMetadata(null, '  LivePhoto-ID '));

        $provider = new ExifMetadataProvider($metadataExtractor);

        $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

        self::assertInstanceOf(ContentIdentifier::class, $identifier);
        self::assertSame('livephoto-id', $identifier->getValue());
    }

    /**
     * Verifies that an ExifMetadataReadException from the extractor is caught and
     * re-thrown as a TargetFilenameException, preserving the original exception as
     * the previous cause.
     *
     * This wrapping allows the grouping pipeline to catch TargetFilenameException
     * uniformly and log a per-file warning without aborting the entire batch,
     * while still exposing the root cause for debugging.
     */
    #[Test]
    public function itConvertsMetadataReadErrorsToTargetFilenameException(): void
    {
        $path              = '/tmp/error.jpg';
        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new ExifMetadataReadException('failure'));

        $provider = new ExifMetadataProvider($metadataExtractor);

        $this->expectException(TargetFilenameException::class);

        try {
            $provider->getExifData(new SplFileInfo($path));
        } catch (TargetFilenameException $throwable) {
            self::assertInstanceOf(ExifMetadataReadException::class, $throwable->getPrevious());

            throw $throwable;
        }
    }
}
