<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use DateTimeImmutable;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Service\Dto\TemporalMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifData;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifMetadataProvider;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SplFileInfo;
use SplObjectStorage;

use function sprintf;
use function uniqid;

#[CoversClass(ExifDateFilenameStrategy::class)]
final class ExifDateFilenameStrategyTest extends TestCase
{
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

    #[Test]
    public function itReturnsNullWhenNoCaptureDate(): void
    {
        $path = '/virtual/' . uniqid('missing_', true) . '.jpg';

        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse($path, new TemporalMetadata(null, null));

        $strategy = $this->createStrategy('Y-m-d_H-i-s', $metadataExtractor);

        self::assertNull($strategy->generateFilename(new SplFileInfo($path)));
    }

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

    #[Test]
    #[DataProvider('subSecondProvider')]
    public function itFormatsSubSecondValuesAsMicroseconds(
        string $subSecondValue,
        string $expectedMicroseconds,
    ): void {
        $path = '/virtual/' . uniqid('subsec_', true) . '.jpg';
        $file = new SplFileInfo($path);

        $exifData = new ExifData('2024:05:05 12:34:56', $subSecondValue, null);
        $strategy = $this->createStrategyWithExifData('Y-m-d_H-i-s-u', $file, $exifData);

        self::assertSame(
            '2024-05-05_12-34-56-' . $expectedMicroseconds . '.jpg',
            $strategy->generateFilename($file),
        );
    }


    private function createStrategyWithExifData(
        string $pattern,
        SplFileInfo $file,
        ExifData $exifData,
    ): ExifDateFilenameStrategy {
        $metadataExtractor = new StubMetadataExtractor();
        $provider = new ExifMetadataProvider($metadataExtractor);

        $exifDataCache = new SplObjectStorage();
        $exifDataCache[$file] = $exifData;

        $cacheProperty = new ReflectionProperty(ExifMetadataProvider::class, 'exifDataCache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue($provider, $exifDataCache);

        return new ExifDateFilenameStrategy($pattern, $provider);
    }

    private function createStrategy(
        string $pattern,
        StubMetadataExtractor $metadataExtractor,
    ): ExifDateFilenameStrategy {
        $provider = new ExifMetadataProvider($metadataExtractor);

        return new ExifDateFilenameStrategy($pattern, $provider);
    }

    /**
     * @return array<string, array{subSecondValue: string, expectedMicroseconds: string}>
     */
    public static function subSecondProvider(): array
    {
        return [
            '1 digit' => [
                'subSecondValue' => '1',
                'expectedMicroseconds' => '100000',
            ],
            '2 digits' => [
                'subSecondValue' => '12',
                'expectedMicroseconds' => '120000',
            ],
            '3 digits' => [
                'subSecondValue' => '123',
                'expectedMicroseconds' => '123000',
            ],
            '4 digits' => [
                'subSecondValue' => '1234',
                'expectedMicroseconds' => '123400',
            ],
            '5 digits' => [
                'subSecondValue' => '12345',
                'expectedMicroseconds' => '123450',
            ],
            '6 digits' => [
                'subSecondValue' => '123456',
                'expectedMicroseconds' => '123456',
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
                'pattern' => 'Y-m-d_H-i-s',
                'extension' => 'jpg',
                'expected' => '2024-05-05_12-34-56.jpg',
                'description' => 'Formats the timestamp using second precision',
            ],
            'millisecond precision' => [
                'captureDateTime' => '2024-05-05T12:34:56.123+00:00',
                'pattern' => 'Y-m-d_H-i-s-v',
                'extension' => 'jpeg',
                'expected' => '2024-05-05_12-34-56-123.jpeg',
                'description' => 'Appends millisecond precision from capture time',
            ],
            'microsecond precision' => [
                'captureDateTime' => '2024-05-05T12:34:56.123456+00:00',
                'pattern' => 'Y-m-d_H-i-s-u',
                'extension' => 'png',
                'expected' => '2024-05-05_12-34-56-123456.png',
                'description' => 'Handles microseconds by switching to microsecond modification',
            ],
        ];
    }
}
