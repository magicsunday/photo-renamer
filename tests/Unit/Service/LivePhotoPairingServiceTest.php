<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoBasenameTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTarget;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoExistingFilePathnameIndex;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairing;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\LivePhotoFixtureFactory;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the LivePhotoPairingService, which scans the file iterator a second
 * time to discover MOV/video companions for already-grouped still images based
 * on shared content identifiers.
 *
 * Pairing is the bridge between groupFilesByDuplicateIdentifier() (which groups
 * by target basename) and createDuplicateFilenames() (which assigns suffixes).
 * The service adds companion videos to their paired group and generates target
 * filenames that inherit the still image's base name.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LivePhotoPairingService::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(FileDuplicateCollection::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(LivePhotoBasenameTargetMap::class)]
#[UsesClass(LivePhotoContentIdentifierTarget::class)]
#[UsesClass(LivePhotoContentIdentifierTargetMap::class)]
#[UsesClass(LivePhotoExistingFilePathnameIndex::class)]
#[UsesClass(LivePhotoPairing::class)]
#[UsesClass(LivePhotoPairingCollection::class)]
#[UsesClass(ExifDateFilenameStrategy::class)]
final class LivePhotoPairingServiceTest extends TestCase
{
    /**
     * Verifies the happy-path pairing: a video and photo sharing the same content
     * identifier are paired, with the video receiving a target that inherits the
     * photo's base name and directory while swapping to the .MOV extension.
     */
    #[Test]
    public function itPairsVideoFilesSharingTheSameContentIdentifier(): void
    {
        $photo  = new SplFileInfo('/source/IMG_0001.HEIC');
        $video  = new SplFileInfo('/source/IMG_0001.MOV');
        $target = new SplFileInfo('/target/20240101_120000.HEIC');

        $existingDuplicate = new FileDuplicate()
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
            contentIdentifierResolver: static fn (SplFileInfo $file): ?string => match ($file->getPathname()) {
                $photo->getPathname(), $video->getPathname() => 'content-id',
                default => null,
            },
        );

        $pairings = $pairs->toList();

        self::assertCount(1, $pairings);

        $pair = $pairings[0];
        self::assertSame($video->getPathname(), $pair->getSourceFile()->getPathname());
        self::assertSame('/source/20240101_120000.MOV', $pair->getTargetFile()->getPathname());
        self::assertSame('live-photo:content-id', $pair->getDuplicateIdentifier());
        self::assertSame('content-id', $pair->getContentIdentifier());
    }

    /**
     * Verifies that pairing succeeds when the content identifier resolver returns
     * already-normalized identifiers (lowercase, trimmed) from both photo and video.
     *
     * Normalization is the provider's responsibility; the service trusts the contract.
     */
    #[Test]
    public function itPairsVideoWhenResolverReturnsNormalizedIdentifiers(): void
    {
        $photo  = new SplFileInfo('/source/IMG_0002.HEIC');
        $video  = new SplFileInfo('/source/IMG_0002.MOV');
        $target = new SplFileInfo('/target/20240102_120000.HEIC');

        $existingDuplicate = new FileDuplicate()
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
            contentIdentifierResolver: static fn (SplFileInfo $file): ?string => match ($file->getPathname()) {
                $photo->getPathname(), $video->getPathname() => 'content-id',
                default => null,
            },
        );

        $pairings = $pairs->toList();

        self::assertCount(1, $pairings);

        $pair = $pairings[0];
        self::assertSame($video->getPathname(), $pair->getSourceFile()->getPathname());
        self::assertSame('/source/20240102_120000.MOV', $pair->getTargetFile()->getPathname());
        self::assertSame('live-photo:content-id', $pair->getDuplicateIdentifier());
        self::assertSame('content-id', $pair->getContentIdentifier());
    }

    /**
     * Verifies the basename fallback: when the video has no content identifier from
     * the resolver, the service pairs it by matching the lowercased source basename
     * against photos in the group that share the same basename.
     *
     * This handles cameras/tools that do not embed Apple content identifiers but
     * name the HEIC and MOV identically (e.g. IMG_0004.HEIC / IMG_0004.MOV).
     */
    #[Test]
    public function itPairsVideoByBasenameWhenContentIdentifierIsMissing(): void
    {
        $photo  = new SplFileInfo('/source/IMG_0004.HEIC');
        $video  = new SplFileInfo('/source/IMG_0004.MOV');
        $target = new SplFileInfo('/target/20240104_120000.HEIC');

        $existingDuplicate = new FileDuplicate()
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
            contentIdentifierResolver: static fn (SplFileInfo $file): ?string => $file->getPathname() === $photo->getPathname() ? 'content-id' : null,
        );

        $pairings = $pairs->toList();

        self::assertCount(1, $pairings);

        $pair = $pairings[0];
        self::assertSame($video->getPathname(), $pair->getSourceFile()->getPathname());
        self::assertSame('/source/20240104_120000.MOV', $pair->getTargetFile()->getPathname());
        self::assertSame('live-photo:content-id', $pair->getDuplicateIdentifier());
        self::assertSame('basename:img_0004', $pair->getContentIdentifier());
    }

    /**
     * Verifies that the basename fallback is skipped when multiple groups contain
     * photos with the same basename, making the match ambiguous.
     *
     * Without this guard, a video named IMG_0005.MOV could be paired with photos
     * from two different directories, producing an incorrect pairing. The video
     * must remain unpaired and not appear in any group.
     */
    #[Test]
    public function itSkipsBasenameFallbackForAmbiguousMatches(): void
    {
        $photoA  = new SplFileInfo('/source/A/IMG_0005.HEIC');
        $videoA  = new SplFileInfo('/source/A/IMG_0005.MOV');
        $targetA = new SplFileInfo('/target/A/20240105_120000.HEIC');

        $photoB  = new SplFileInfo('/source/B/IMG_0005.HEIC');
        $targetB = new SplFileInfo('/target/B/20240106_120000.HEIC');

        $duplicateA = new FileDuplicate()
            ->addFile($photoA)
            ->setTarget($targetA);

        $duplicateB = new FileDuplicate()
            ->addFile($photoB)
            ->setTarget($targetB);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:content-a', $duplicateA);
        $duplicateCollection->set('live-photo:content-b', $duplicateB);

        $service = new LivePhotoPairingService();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photoA, $photoB, $videoA], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $pairs = $service->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $duplicateCollection,
            contentIdentifierResolver: static fn (SplFileInfo $file): ?string => match ($file->getPathname()) {
                $photoA->getPathname() => 'content-a',
                $photoB->getPathname() => 'content-b',
                default                => null,
            },
        );

        self::assertSame([], $pairs->toList());
    }

    /**
     * Verifies that the onFileInspected callback is invoked once per file in the
     * iterator, enabling progress bar advancement in the UI.
     *
     * The callback must fire for every file, not just paired ones, so the progress
     * bar reaches 100% even when most files are not Live Photos.
     */
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

        $service             = new LivePhotoPairingService();
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
        self::assertSame([], $pairs->toList());
    }

    /**
     * Verifies end-to-end pairing using real fixture files with embedded metadata:
     * the JPEG contains an XMP ContentIdentifier and the MOV contains a QuickTime
     * content.identifier atom, both set to the same UUID.
     *
     * This integration-style test ensures the full chain from metadata extraction
     * through ExifDateFilenameStrategy.getLivePhotoContentIdentifier() to
     * pairByContentIdentifier() works with actual binary file content.
     */
    #[Test]
    public function itPairsVideoUsingTemporalMetadataContentIdentifiers(): void
    {
        $photo = LivePhotoFixtureFactory::createJpeg();
        $video = LivePhotoFixtureFactory::createMov();

        $target = new SplFileInfo($photo->getPath() . DIRECTORY_SEPARATOR . '20240101_120000.jpg');

        $existingDuplicate = new FileDuplicate()
            ->addFile($photo)
            ->setTarget($target);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:iphone', $existingDuplicate);

        $metadataExtractor = new StubMetadataExtractor();
        $metadataExtractor->withResponse(
            $photo->getPathname(),
            new TemporalMetadata(null, 'UUID-IPHONE-LIVEPHOTO'),
        );
        $metadataExtractor->withResponse(
            $video->getPathname(),
            new TemporalMetadata(null, 'UUID-IPHONE-LIVEPHOTO'),
        );

        $metadataProvider = new ExifMetadataProvider($metadataExtractor);
        $renameStrategy   = new ExifDateFilenameStrategy('Ymd_His', $metadataProvider);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $service = new LivePhotoPairingService();

        $pairs = $service->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $duplicateCollection,
            contentIdentifierResolver: fn (SplFileInfo $file): ?string => $renameStrategy->getLivePhotoContentIdentifier($file),
        );

        $pairings = $pairs->toList();

        self::assertCount(1, $pairings);

        $pair = $pairings[0];

        $expectedTargetPath = $video->getPath() . DIRECTORY_SEPARATOR . '20240101_120000.' . $video->getExtension();

        self::assertSame($video->getPathname(), $pair->getSourceFile()->getPathname());
        self::assertSame($expectedTargetPath, $pair->getTargetFile()->getPathname());
        self::assertSame('live-photo:iphone', $pair->getDuplicateIdentifier());
        self::assertSame('uuid-iphone-livephoto', $pair->getContentIdentifier());
    }
}
