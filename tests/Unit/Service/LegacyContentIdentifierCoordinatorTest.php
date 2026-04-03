<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\ContentIdentifierCacheEntry;
use MagicSunday\Renamer\Service\LegacyContentIdentifierCoordinator;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies legacy content-identifier grouping rules outside DuplicateDetectionService.
 *
 * The coordinator owns the pending-file, skipped-file, and fallback replay logic
 * around Live Photo content identifiers in the legacy duplicate path. These tests
 * lock down that behavior so the legacy service can shrink without changing how
 * still images, companion videos, and skipped files are grouped.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyContentIdentifierCoordinator::class)]
#[UsesClass(ContentIdentifierCacheEntry::class)]
#[UsesClass(FileDuplicateCollection::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(SkippedFile::class)]
#[UsesClass(TargetFileResult::class)]
final class LegacyContentIdentifierCoordinatorTest extends TestCase
{
    /**
     * Verifies that resolving a cache entry creates exactly one shared DTO per content identifier.
     */
    #[Test]
    public function resolveCacheEntryCreatesSharedEntry(): void
    {
        $coordinator            = new LegacyContentIdentifierCoordinator(self::createStub(MediaTypeClassifierInterface::class));
        $contentIdentifierCache = [];

        $entryA = $coordinator->resolveCacheEntry('abc-123', $contentIdentifierCache);
        $entryB = $coordinator->resolveCacheEntry('abc-123', $contentIdentifierCache);

        self::assertInstanceOf(ContentIdentifierCacheEntry::class, $entryA);
        self::assertSame($entryA, $entryB);
        self::assertCount(1, $contentIdentifierCache);
    }

    /**
     * Verifies that a companion video is queued with its fallback target until the still image is seen.
     */
    #[Test]
    public function handleDeferredVideoCompanionQueuesPendingVideo(): void
    {
        $classifier = $this->createMock(MediaTypeClassifierInterface::class);
        $classifier->expects(self::once())->method('isLivePhotoStill')->willReturn(false);

        $coordinator            = new LegacyContentIdentifierCoordinator($classifier);
        $contentIdentifierCache = [];
        $cacheEntry             = $coordinator->resolveCacheEntry('abc-123', $contentIdentifierCache);
        $collection             = new FileDuplicateCollection();
        $sourceFile             = new SplFileInfo('/photos/IMG_0001.mov');
        $targetFile             = new SplFileInfo('/photos/2024-01-01_12-00-00-000.mov');

        self::assertInstanceOf(ContentIdentifierCacheEntry::class, $cacheEntry);

        $deferred = $coordinator->handleDeferredVideoCompanion(
            $sourceFile,
            TargetFileResult::success($targetFile),
            $collection,
            $cacheEntry,
        );

        self::assertTrue($deferred);
        self::assertSame([$sourceFile], $cacheEntry->getPendingFiles());
        self::assertSame($targetFile, $cacheEntry->getTarget());
    }

    /**
     * Verifies that resolving a group immediately replays previously deferred videos into that group.
     */
    #[Test]
    public function attachToResolvedGroupReplaysPendingFiles(): void
    {
        $coordinator            = new LegacyContentIdentifierCoordinator(self::createStub(MediaTypeClassifierInterface::class));
        $contentIdentifierCache = [];
        $cacheEntry             = $coordinator->resolveCacheEntry('abc-123', $contentIdentifierCache);
        $fileDuplicate          = new FileDuplicate();
        $targetFile             = new SplFileInfo('/photos/2024-01-01_12-00-00-000.heic');
        $stillFile              = new SplFileInfo('/photos/IMG_0001.heic');
        $pendingVideo           = new SplFileInfo('/photos/IMG_0001.mov');

        self::assertInstanceOf(ContentIdentifierCacheEntry::class, $cacheEntry);

        $fileDuplicate
            ->setTarget($targetFile)
            ->addFile($stillFile);
        $cacheEntry->addPendingFile($pendingVideo);

        $coordinator->attachToResolvedGroup(
            '2024-01-01_12-00-00-000',
            $fileDuplicate,
            $targetFile,
            $cacheEntry,
        );

        self::assertSame('2024-01-01_12-00-00-000', $cacheEntry->getDuplicateIdentifier());
        self::assertCount(2, $fileDuplicate->getFiles());
        self::assertSame([], $cacheEntry->getPendingFiles());
    }

    /**
     * Verifies that a skipped file with a resolved content identifier is added back to the existing duplicate group.
     */
    #[Test]
    public function handleSkippedFileAttachesToResolvedGroup(): void
    {
        $coordinator            = new LegacyContentIdentifierCoordinator(self::createStub(MediaTypeClassifierInterface::class));
        $contentIdentifierCache = [];
        $cacheEntry             = $coordinator->resolveCacheEntry('abc-123', $contentIdentifierCache);
        $collection             = new FileDuplicateCollection();
        $fileDuplicate          = new FileDuplicate();
        $sourceFile             = new SplFileInfo('/photos/IMG_0001.mov');
        $skippedFiles           = [];

        self::assertInstanceOf(ContentIdentifierCacheEntry::class, $cacheEntry);

        $fileDuplicate
            ->setTarget(new SplFileInfo('/photos/2024-01-01_12-00-00-000.heic'))
            ->addFile(new SplFileInfo('/photos/IMG_0001.heic'));
        $collection->set('2024-01-01_12-00-00-000', $fileDuplicate);
        $cacheEntry->rememberResolvedGroup(
            '2024-01-01_12-00-00-000',
            new SplFileInfo('/photos/2024-01-01_12-00-00-000.heic'),
        );

        $coordinator->handleSkippedFile(
            $sourceFile,
            TargetFileResult::skipped('no capture date'),
            $collection,
            $cacheEntry,
            $skippedFiles,
        );

        self::assertSame([], $skippedFiles);
        self::assertCount(2, $fileDuplicate->getFiles());
    }

    /**
     * Verifies that unresolved pending files fall back into their own target-based group after the scan pass.
     */
    #[Test]
    public function resolvePendingFallbackGroupsCreatesFallbackGroup(): void
    {
        $coordinator            = new LegacyContentIdentifierCoordinator(self::createStub(MediaTypeClassifierInterface::class));
        $contentIdentifierCache = [];
        $cacheEntry             = $coordinator->resolveCacheEntry('abc-123', $contentIdentifierCache);
        $collection             = new FileDuplicateCollection();
        $sourceFile             = new SplFileInfo('/photos/IMG_0001.mov');
        $targetFile             = new SplFileInfo('/photos/2024-01-01_12-00-00-000.mov');

        self::assertInstanceOf(ContentIdentifierCacheEntry::class, $cacheEntry);

        $cacheEntry->addPendingFile($sourceFile);
        $cacheEntry->rememberFallbackTarget($targetFile);

        $coordinator->resolvePendingFallbackGroups(
            $contentIdentifierCache,
            $collection,
            static fn (SplFileInfo $source, SplFileInfo $target): string => $source->getFilename() . '::' . $target->getFilename(),
        );

        self::assertTrue($collection->has('IMG_0001.mov::2024-01-01_12-00-00-000.mov'));
        $fileDuplicate = $collection->get('IMG_0001.mov::2024-01-01_12-00-00-000.mov');
        self::assertInstanceOf(FileDuplicate::class, $fileDuplicate);
        self::assertCount(1, $fileDuplicate->getFiles());
    }
}
