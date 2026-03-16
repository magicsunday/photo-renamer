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

#[CoversClass(ExifMetadataProvider::class)]
final class ExifMetadataProviderTest extends TestCase
{
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

    #[Test]
    public function itReturnsNullWhenMetadataMissing(): void
    {
        $path              = '/tmp/missing.jpg';
        $metadataExtractor = new StubMetadataExtractor();

        $provider = new ExifMetadataProvider($metadataExtractor);

        self::assertNull($provider->getExifData(new SplFileInfo($path)));
        self::assertNull($provider->getContentIdentifier(new SplFileInfo($path)));
    }

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
