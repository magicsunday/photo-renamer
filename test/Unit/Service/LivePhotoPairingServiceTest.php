<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\Dto\ExifMetadataResult;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhotoPairingService;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ContentIdentifier;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifMetadataProvider;
use MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime\QuickTimeContentIdentifierExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_key_exists;

final class LivePhotoSafeExifReaderStub extends SafeExifReader
{
    /**
     * @var array<string, array|false>
     */
    private array $responses = [];

    public function withResponse(string $path, array|false $response): void
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

        if ($response === false) {
            return ExifMetadataResult::withoutMetadata();
        }

        return ExifMetadataResult::withMetadata(ExifRawMetadata::fromArray($response));
    }
}

final class LivePhotoQuickTimeExtractorStub extends QuickTimeContentIdentifierExtractor
{
    /**
     * @var array<string, ContentIdentifier|null>
     */
    private array $responses = [];

    public function __construct()
    {
        parent::__construct(new SafeFileReader());
    }

    public function withResponse(string $path, ?ContentIdentifier $identifier): void
    {
        $this->responses[$path] = $identifier;
    }

    public function extractContentIdentifier(SplFileInfo $splFileInfo): ?ContentIdentifier
    {
        return $this->responses[$splFileInfo->getPathname()] ?? null;
    }
}

#[CoversClass(LivePhotoPairingService::class)]
final class LivePhotoPairingServiceTest extends TestCase
{
    #[Test]
    public function itPairsVideoFilesSharingTheSameContentIdentifier(): void
    {
        $photo = new SplFileInfo('/source/IMG_0001.HEIC');
        $video = new SplFileInfo('/source/IMG_0001.MOV');
        $target = new SplFileInfo('/target/20240101_120000.HEIC');

        $existingDuplicate = (new FileDuplicate())
            ->addFile($photo)
            ->setTarget($target);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:content-id', $existingDuplicate);

        $service = new LivePhotoPairingService();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $pairs = $service->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $duplicateCollection,
            contentIdentifierResolver: static function (SplFileInfo $file) use ($photo, $video): ?string {
                return match ($file->getPathname()) {
                    $photo->getPathname(), $video->getPathname() => 'content-id',
                    default => null,
                };
            },
        );

        $pairings = $pairs->toList();

        self::assertCount(1, $pairings);

        $pair = $pairings[0];
        self::assertInstanceOf(LivePhotoPairing::class, $pair);
        self::assertSame($video->getPathname(), $pair->getSourceFile()->getPathname());
        self::assertSame('/source/20240101_120000.MOV', $pair->getTargetFile()->getPathname());
        self::assertSame('20240101_120000.MOV', $pair->getDuplicateIdentifier());
        self::assertSame('content-id', $pair->getContentIdentifier());
    }

    #[Test]
    public function itInvokesProgressCallbackForEachInspectedFile(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator(
                [
                    new SplFileInfo('/source/IMG_0001.HEIC'),
                    new SplFileInfo('/source/IMG_0002.MOV'),
                    new SplFileInfo('/source/IMG_0003.JPG'),
                ],
                RecursiveArrayIterator::CHILD_ARRAYS_ONLY,
            ),
        );

        $service = new LivePhotoPairingService();
        $duplicateCollection = new FileDuplicateCollection();

        $progressCalls = 0;

        $pairs = $service->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $duplicateCollection,
            contentIdentifierResolver: static fn (): ?string => null,
            onFileInspected: static function () use (&$progressCalls): void {
                ++$progressCalls;
            },
        );

        self::assertSame(3, $progressCalls);
        self::assertInstanceOf(LivePhotoPairingCollection::class, $pairs);
        self::assertSame([], $pairs->toList());
    }

    #[Test]
    public function itPairsLivePhotoVideoWhenJpegProvidesXmpIdentifier(): void
    {
        $photo = new SplFileInfo('/source/IMG_0001.JPG');
        $video = new SplFileInfo('/source/IMG_0001.MOV');

        $target = new SplFileInfo('/target/20240101_120000.JPG');

        $existingDuplicate = (new FileDuplicate())
            ->addFile($photo)
            ->setTarget($target);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:' . $photo->getFilename(), $existingDuplicate);

        $exifReader = new LivePhotoSafeExifReaderStub();
        $exifReader->withResponse($photo->getPathname(), [
            'DateTimeOriginal' => '2024:01:01 12:00:00',
            'SubSecTimeOriginal' => '123',
            'XMP' => [
                'xmp:ContentIdentifier' => 'UUID-LIVE-PHOTO-1234',
            ],
        ]);
        $exifReader->withResponse($video->getPathname(), false);

        $quickTimeExtractor = new LivePhotoQuickTimeExtractorStub();
        $quickTimeExtractor->withResponse($video->getPathname(), new ContentIdentifier('UUID-LIVE-PHOTO-1234'));
        $metadataProvider = new ExifMetadataProvider($exifReader, $quickTimeExtractor);
        $renameStrategy = new ExifDateFilenameStrategy('Ymd_His', $metadataProvider);

        self::assertSame('UUID-LIVE-PHOTO-1234', $renameStrategy->getLivePhotoContentIdentifier($photo));
        self::assertSame('UUID-LIVE-PHOTO-1234', $renameStrategy->getLivePhotoContentIdentifier($video));

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $service = new LivePhotoPairingService();

        $pairs = $service->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $duplicateCollection,
            contentIdentifierResolver: [$renameStrategy, 'getLivePhotoContentIdentifier'],
        );

        $pairings = $pairs->toList();

        self::assertCount(1, $pairings);
        $pair = $pairings[0];

        self::assertInstanceOf(LivePhotoPairing::class, $pair);
        self::assertSame($video->getPathname(), $pair->getSourceFile()->getPathname());
        $expectedTarget = $video->getPath() . DIRECTORY_SEPARATOR . '20240101_120000.MOV';

        self::assertSame($expectedTarget, $pair->getTargetFile()->getPathname());
        self::assertSame('UUID-LIVE-PHOTO-1234', $pair->getContentIdentifier());
    }
}
