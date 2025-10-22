<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use DateTimeImmutable;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Service\Dto\ExifMetadataResult;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ContentIdentifier;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifData;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifMetadataProvider;
use MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime\QuickTimeContentIdentifierExtractor;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\LivePhotoFixtureFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Throwable;

/** @phpstan-import-type ExifNativeData from MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifValueFactory */
final class ProviderSafeExifReaderStub extends SafeExifReader
{
    /**
     * @var array<string, ExifNativeData|false|Throwable>
     */
    private array $responses = [];

    /**
     * @param ExifNativeData|false|Throwable $response
     */
    public function withResponse(string $path, array|false|Throwable $response): void
    {
        $this->responses[$path] = $response;
    }

    public function read(SplFileInfo $file): ExifMetadataResult
    {
        $path = $file->getPathname();

        if (!array_key_exists($path, $this->responses)) {
            return ExifMetadataResult::withoutMetadata();
        }

        $response = $this->responses[$path];

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($response === false) {
            return ExifMetadataResult::withoutMetadata();
        }

        return ExifMetadataResult::withMetadata(ExifRawMetadata::fromArray($response));
    }
}

final class ProviderQuickTimeExtractorStub extends QuickTimeContentIdentifierExtractor
{
    /**
     * @var array<string, ContentIdentifier|null>
     */
    private array $responses = [];

    /**
     * @var array<string, DateTimeImmutable|null>
     */
    private array $creationDateResponses = [];

    /**
     * @var list<string>
     */
    private array $invocations = [];

    /**
     * @var list<string>
     */
    private array $creationDateInvocations = [];

    public function __construct()
    {
        parent::__construct(new SafeFileReader());
    }

    public function withResponse(string $path, ?ContentIdentifier $identifier): void
    {
        $this->responses[$path] = $identifier;
    }

    public function withCreationDateResponse(string $path, ?DateTimeImmutable $creationDate): void
    {
        $this->creationDateResponses[$path] = $creationDate;
    }

    public function extractContentIdentifier(SplFileInfo $splFileInfo): ?ContentIdentifier
    {
        $path = $splFileInfo->getPathname();

        $this->invocations[] = $path;

        return $this->responses[$path] ?? null;
    }

    public function extractCreationDate(SplFileInfo $splFileInfo): ?DateTimeImmutable
    {
        $path = $splFileInfo->getPathname();
        $this->creationDateInvocations[] = $path;

        return $this->creationDateResponses[$path] ?? null;
    }

    public function wasInvokedFor(string $path): bool
    {
        return in_array($path, $this->invocations, true);
    }

    public function wasCreationDateInvokedFor(string $path): bool
    {
        return in_array($path, $this->creationDateInvocations, true);
    }
}

#[CoversClass(ExifMetadataProvider::class)]
final class ExifMetadataProviderTest extends TestCase
{
    #[Test]
    public function itReturnsExifDataWhenMetadataAvailable(): void
    {
        $path = '/tmp/sample.jpg';
        $exifReader = new ProviderSafeExifReaderStub();
        $exifReader->withResponse($path, [
            'DateTimeOriginal' => '2024:05:05 12:34:56',
            'SubSecTimeOriginal' => '123',
        ]);

        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();

        $provider = new ExifMetadataProvider($exifReader, $quickTimeExtractor);

        $exifData = $provider->getExifData(new SplFileInfo($path));

        self::assertInstanceOf(ExifData::class, $exifData);
        self::assertSame('2024:05:05 12:34:56', $exifData->getDateTimeOriginal());
        self::assertSame('123', $exifData->getSubSecTimeOriginal());
        self::assertNull($exifData->getContentIdentifier());
    }

    #[Test]
    public function itReturnsNullWhenMetadataMissing(): void
    {
        $path = '/tmp/missing.jpg';
        $exifReader = new ProviderSafeExifReaderStub();
        $exifReader->withResponse($path, false);

        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();

        $provider = new ExifMetadataProvider($exifReader, $quickTimeExtractor);

        self::assertNull($provider->getExifData(new SplFileInfo($path)));
        self::assertNull($provider->getContentIdentifier(new SplFileInfo($path)));
    }

    #[Test]
    public function itUsesQuickTimeCreationDateWhenExifDateMissing(): void
    {
        $path = '/tmp/video.mov';
        $exifReader = new ProviderSafeExifReaderStub();
        $exifReader->withResponse($path, false);

        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();
        $quickTimeExtractor->withCreationDateResponse($path, new DateTimeImmutable('2024-05-05T12:34:56.789+00:00'));

        $provider = new ExifMetadataProvider($exifReader, $quickTimeExtractor);

        $exifData = $provider->getExifData(new SplFileInfo($path));

        self::assertInstanceOf(ExifData::class, $exifData);
        self::assertSame('2024:05:05 12:34:56', $exifData->getDateTimeOriginal());
        self::assertSame('789', $exifData->getSubSecTimeOriginal());
        self::assertTrue($quickTimeExtractor->wasCreationDateInvokedFor($path));
    }

    #[Test]
    public function itNormalisesQuickTimeMicroseconds(): void
    {
        $path = '/tmp/video_micro.mov';
        $exifReader = new ProviderSafeExifReaderStub();
        $exifReader->withResponse($path, false);

        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();
        $quickTimeExtractor->withCreationDateResponse($path, new DateTimeImmutable('2024-05-05T12:34:56.123456+00:00'));

        $provider = new ExifMetadataProvider($exifReader, $quickTimeExtractor);

        $exifData = $provider->getExifData(new SplFileInfo($path));

        self::assertInstanceOf(ExifData::class, $exifData);
        self::assertSame('2024:05:05 12:34:56', $exifData->getDateTimeOriginal());
        self::assertSame('123456', $exifData->getSubSecTimeOriginal());
    }

    #[Test]
    public function itFallsBackToQuickTimeContentIdentifier(): void
    {
        $path = '/tmp/live.mov';
        $exifReader = new ProviderSafeExifReaderStub();
        $exifReader->withResponse($path, false);

        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();
        $quickTimeExtractor->withResponse($path, new ContentIdentifier('UUID-5678'));

        $provider = new ExifMetadataProvider($exifReader, $quickTimeExtractor);

        self::assertNull($provider->getExifData(new SplFileInfo($path)));

        $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

        self::assertInstanceOf(ContentIdentifier::class, $identifier);
        self::assertSame('uuid-5678', $identifier->getValue());
    }

    #[Test]
    public function itExtractsQuickTimeCreationDateFromFixture(): void
    {
        $video = LivePhotoFixtureFactory::createMov();
        $path = $video->getPathname();

        $exifReader = new ProviderSafeExifReaderStub();
        $exifReader->withResponse($path, false);

        $provider = new ExifMetadataProvider(
            $exifReader,
            new QuickTimeContentIdentifierExtractor(new SafeFileReader()),
        );

        $exifData = $provider->getExifData($video);

        self::assertInstanceOf(ExifData::class, $exifData);
        self::assertSame('2024:05:05 12:34:56', $exifData->getDateTimeOriginal());
        self::assertSame('789', $exifData->getSubSecTimeOriginal());
    }

    #[Test]
    public function itSkipsUnsupportedMovWhileExtractingQuickTimeIdentifier(): void
    {
        $basePath = tempnam(sys_get_temp_dir(), 'provider_mov_');

        self::assertNotFalse($basePath);

        $path = $basePath . '.mov';

        self::assertTrue(rename($basePath, $path));
        self::assertNotFalse(file_put_contents($path, hex2bin('89504E470D0A1A0A')));

        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();
        $quickTimeExtractor->withResponse($path, new ContentIdentifier('UUID-9012'));

        $provider = new ExifMetadataProvider(new SafeExifReader(), $quickTimeExtractor);

        try {
            self::assertNull($provider->getExifData(new SplFileInfo($path)));

            $identifier = $provider->getContentIdentifier(new SplFileInfo($path));

            self::assertInstanceOf(ContentIdentifier::class, $identifier);
            self::assertSame('uuid-9012', $identifier->getValue());
            self::assertTrue($quickTimeExtractor->wasInvokedFor($path));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function itConvertsExifReadErrorsToTargetFilenameException(): void
    {
        $path = '/tmp/error.jpg';
        $exifReader = new ProviderSafeExifReaderStub();
        $exifReader->withResponse($path, new ExifMetadataReadException('failure'));

        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();

        $provider = new ExifMetadataProvider($exifReader, $quickTimeExtractor);

        $this->expectException(TargetFilenameException::class);

        try {
            $provider->getExifData(new SplFileInfo($path));
        } catch (TargetFilenameException $throwable) {
            self::assertInstanceOf(ExifMetadataReadException::class, $throwable->getPrevious());

            throw $throwable;
        }
    }

    #[Test]
    public function itExtractsContentIdentifierFromXmpMetadata(): void
    {
        $photo = LivePhotoFixtureFactory::createJpeg();
        $path = $photo->getPathname();
        $quickTimeExtractor = new ProviderQuickTimeExtractorStub();
        $provider = new ExifMetadataProvider(new SafeExifReader(), $quickTimeExtractor);

        $identifier = $provider->getContentIdentifier($photo);

        self::assertInstanceOf(ContentIdentifier::class, $identifier);
        self::assertSame('uuid-iphone-livephoto', $identifier->getValue());
        self::assertFalse($quickTimeExtractor->wasInvokedFor($path));
    }
}

