<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhotoPairingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
}
