<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\FileReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Service\Dto\ExifMetadataResult;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\MetadataEntryCollection;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeKey;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeValue;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Throwable;

use function pack;
use function sprintf;
use function strlen;
use function uniqid;

final class StubSafeExifReader extends SafeExifReader
{
    /**
     * @var array<string, array<string, mixed>|false|Throwable>
     */
    private array $responses = [];

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

final class StubSafeFileReader extends SafeFileReader
{
    /**
     * @var array<string, string|Throwable>
     */
    private array $responses = [];

    public function withResponse(string $path, string|Throwable $response): void
    {
        $this->responses[$path] = $response;
    }

    public function read(SplFileInfo $file): string
    {
        $path = $file->getPathname();

        if (!array_key_exists($path, $this->responses)) {
            throw new FileReadException(sprintf('No stubbed content available for "%s".', $path));
        }

        $response = $this->responses[$path];

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

#[CoversClass(ExifDateFilenameStrategy::class)]
final class ExifDateFilenameStrategyTest extends TestCase
{
    #[Test]
    #[DataProvider('exifDateProvider')]
    public function itGeneratesFilenameFromExifDate(
        string $dateTimeOriginal,
        ?string $subSecTimeOriginal,
        string $pattern,
        string $extension,
        string $expected,
        string $description,
    ): void {
        $path = '/virtual/' . uniqid('exif_', true) . '.' . $extension;

        $exifData = ['DateTimeOriginal' => $dateTimeOriginal];

        if ($subSecTimeOriginal !== null) {
            $exifData['SubSecTimeOriginal'] = $subSecTimeOriginal;
        }

        $exifReader = new StubSafeExifReader();
        $exifReader->withResponse($path, $exifData);

        $fileReader = new StubSafeFileReader();

        $strategy = new ExifDateFilenameStrategy($pattern, $exifReader, $fileReader);

        self::assertSame(
            $expected,
            $strategy->generateFilename(new SplFileInfo($path)),
            sprintf('Failed for case: %s', $description),
        );
    }

    #[Test]
    public function itReturnsNullWhenNoExifData(): void
    {
        $path = '/virtual/' . uniqid('missing_', true) . '.jpg';

        $strategy = new ExifDateFilenameStrategy(
            'Y-m-d_H-i-s',
            new StubSafeExifReader(),
            new StubSafeFileReader(),
        );

        self::assertNull($strategy->generateFilename(new SplFileInfo($path)));
    }

    #[Test]
    public function itReturnsNullOnInvalidDate(): void
    {
        $path = '/virtual/' . uniqid('invalid_', true) . '.jpg';

        $exifReader = new StubSafeExifReader();
        $exifReader->withResponse($path, ['DateTimeOriginal' => 'not a valid date']);

        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s', $exifReader, new StubSafeFileReader());

        self::assertNull($strategy->generateFilename(new SplFileInfo($path)));
    }

    #[Test]
    public function itExtractsLivePhotoContentIdentifierFromExifData(): void
    {
        $path = '/virtual/' . uniqid('live_', true) . '.jpg';

        $exifReader = new StubSafeExifReader();
        $exifReader->withResponse($path, [
            'DateTimeOriginal' => '2024:05:05 12:00:00',
            'Nested' => ['ContentIdentifier' => 'EXIF-UUID'],
        ]);

        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s', $exifReader, new StubSafeFileReader());

        self::assertSame('EXIF-UUID', $strategy->getLivePhotoContentIdentifier(new SplFileInfo($path)));
    }

    #[Test]
    public function itExtractsLivePhotoContentIdentifierFromQuickTimeMetadata(): void
    {
        $path = '/virtual/' . uniqid('quicktime_', true) . '.mov';

        $exifReader = new StubSafeExifReader();
        $exifReader->withResponse($path, false);

        $fileReader = new StubSafeFileReader();
        $fileReader->withResponse($path, self::createQuickTimeSample('550E8400-E29B-41D4-A716-446655440000'));

        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s', $exifReader, $fileReader);

        self::assertSame(
            '550E8400-E29B-41D4-A716-446655440000',
            $strategy->getLivePhotoContentIdentifier(new SplFileInfo($path)),
        );
    }

    #[Test]
    public function quickTimeReadFailureIsReportedAsTargetFilenameException(): void
    {
        $path = '/virtual/' . uniqid('quicktime_failure_', true) . '.mov';

        $exifReader = new StubSafeExifReader();
        $exifReader->withResponse($path, false);

        $fileReader = new StubSafeFileReader();
        $fileReader->withResponse($path, new FileReadException('I/O failure'));

        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s', $exifReader, $fileReader);

        $this->expectException(TargetFilenameException::class);
        $this->expectExceptionMessage('Unable to read QuickTime metadata: I/O failure');

        $strategy->getLivePhotoContentIdentifier(new SplFileInfo($path));
    }

    #[Test]
    public function metadataCollectionFindsContentIdentifierCandidates(): void
    {
        $collection = MetadataEntryCollection::fromMetadata(ExifRawMetadata::fromArray([
            'DateTimeOriginal' => '2024:05:05 12:00:00',
            'Nested' => [
                'ContentIdentifier' => 'COLLECTION-UUID',
            ],
        ]));

        $entry = $collection->findContentIdentifier();

        self::assertNotNull($entry);
        self::assertSame('Nested.ContentIdentifier', $entry->getPath());
        self::assertSame('COLLECTION-UUID', $entry->getValue());
    }

    #[Test]
    public function quickTimeMetadataMatchesKeysWithValues(): void
    {
        $metadata = QuickTimeMetadata::empty()
            ->withKey(new QuickTimeKey(1, 'com.apple.quicktime.content.identifier'))
            ->withKey(new QuickTimeKey(2, 'com.apple.quicktime.location'))
            ->withValue(new QuickTimeValue(2, 'Berlin'))
            ->withValue(new QuickTimeValue(1, 'IDENTIFIER-1234'));

        $identifier = $metadata->findValueByKeyFragment('content.identifier');

        self::assertNotNull($identifier);
        self::assertSame('IDENTIFIER-1234', $identifier->getValue());
    }

    #[Test]
    public function exifReadFailureIsConvertedToTargetFilenameException(): void
    {
        $path = '/virtual/' . uniqid('exif_failure_', true) . '.jpg';

        $exifReader = new StubSafeExifReader();
        $exifReader->withResponse($path, new ExifMetadataReadException('EXIF extension missing'));

        $strategy = new ExifDateFilenameStrategy('Y-m-d_H-i-s', $exifReader, new StubSafeFileReader());

        $this->expectException(TargetFilenameException::class);
        $this->expectExceptionMessage('EXIF extension missing');

        $strategy->generateFilename(new SplFileInfo($path));
    }

    /**
     * @return array<string, array{dateTimeOriginal: string, subSecTimeOriginal: ?string, pattern: string, extension: string, expected: string, description: string}>
     */
    public static function exifDateProvider(): array
    {
        return [
            'basic timestamp' => [
                'dateTimeOriginal' => '2024:05:05 12:34:56',
                'subSecTimeOriginal' => null,
                'pattern' => 'Y-m-d_H-i-s',
                'extension' => 'jpg',
                'expected' => '2024-05-05_12-34-56.jpg',
                'description' => 'Formats the timestamp using second precision',
            ],
            'millisecond precision' => [
                'dateTimeOriginal' => '2024:05:05 12:34:56',
                'subSecTimeOriginal' => '123',
                'pattern' => 'Y-m-d_H-i-s-v',
                'extension' => 'jpeg',
                'expected' => '2024-05-05_12-34-56-123.jpeg',
                'description' => 'Appends millisecond precision from SubSecTimeOriginal',
            ],
            'microsecond precision' => [
                'dateTimeOriginal' => '2024:05:05 12:34:56',
                'subSecTimeOriginal' => '123456',
                'pattern' => 'Y-m-d_H-i-s-u',
                'extension' => 'png',
                'expected' => '2024-05-05_12-34-56-123456.png',
                'description' => 'Handles microseconds by switching to microsecond modification',
            ],
        ];
    }

    private static function createQuickTimeSample(string $identifier): string
    {
        $key = 'com.apple.quicktime.content.identifier';

        $keyEntryPayload = pack('N', 8 + strlen($key))
            . "\0\0\0\0"
            . $key;

        $keysPayload = "\0\0\0\0"
            . pack('N', 1)
            . $keyEntryPayload;

        $keysAtom = pack('N', 8 + strlen($keysPayload))
            . 'keys'
            . $keysPayload;

        $dataPayload = pack('N', 16 + strlen($identifier))
            . 'data'
            . "\0\0\0\1"
            . "\0\0\0\0"
            . $identifier;

        $ilstEntry = pack('N', 8 + strlen($dataPayload))
            . pack('N', 1)
            . $dataPayload;

        $ilstAtom = pack('N', 8 + strlen($ilstEntry))
            . 'ilst'
            . $ilstEntry;

        $metaPayload = "\0\0\0\0"
            . $keysAtom
            . $ilstAtom;

        $metaAtom = pack('N', 8 + strlen($metaPayload))
            . 'meta'
            . $metaPayload;

        $udtaAtom = pack('N', 8 + strlen($metaAtom))
            . 'udta'
            . $metaAtom;

        return pack('N', 8 + strlen($udtaAtom))
            . 'moov'
            . $udtaAtom;
    }
}
