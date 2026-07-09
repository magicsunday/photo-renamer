<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use DateTimeImmutable;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\ContentIdentifierCacheEntry;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\Pipeline\CaptureContentIdentifierCoordinator;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuildState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies content-identifier cache coordination during capture-group assembly.
 *
 * The coordinator owns the pending-file and skipped-file rules around Live Photo
 * content identifiers, so these tests lock down queueing, direct attachment, and
 * conservative skipped-file behavior outside the main builder orchestrator.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CaptureContentIdentifierCoordinator::class)]
#[UsesClass(CaptureGroupBuildState::class)]
#[UsesClass(ContentIdentifierCacheEntry::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(SkippedFile::class)]
#[UsesClass(TargetFileResult::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(TemporalMetadata::class)]
final class CaptureContentIdentifierCoordinatorTest extends TestCase
{
    /**
     * Verifies that a companion video is queued with its fallback target until a
     * still image resolves the content-identifier family.
     */
    #[Test]
    public function deferredVideoCompanionIsQueuedWhenStillIsNotResolvedYet(): void
    {
        $classifier = $this->createMock(MediaTypeClassifierInterface::class);
        $classifier->expects(self::once())->method('isLivePhotoStill')->willReturn(false);

        $coordinator = new CaptureContentIdentifierCoordinator($classifier);
        $state       = new CaptureGroupBuildState();
        $collection  = new AssetGroupCollection();
        $file        = new SplFileInfo('/photos/IMG_0001.mov');
        $targetFile  = new SplFileInfo('/photos/2024-01-01_12-00-00-000.mov');
        $item        = new AssetItem($file);

        $coordinator->initializeCacheEntry('abc-123', $state);

        $deferred = $coordinator->handleDeferredVideoCompanion(
            $file,
            $item,
            TargetFileResult::success($targetFile),
            $collection,
            $state,
            'abc-123',
        );

        self::assertTrue($deferred);
        self::assertSame([$file], $state->contentIdentifierCache['abc-123']->getPendingFiles());
        self::assertSame($targetFile, $state->contentIdentifierCache['abc-123']->getTarget());
    }

    /**
     * Verifies that resolving a group for a content identifier immediately attaches
     * queued pending files with their cached metadata and content identifiers.
     */
    #[Test]
    public function attachToResolvedGroupHydratesPendingFiles(): void
    {
        $classifier  = self::createStub(MediaTypeClassifierInterface::class);
        $coordinator = new CaptureContentIdentifierCoordinator($classifier);
        $state       = new CaptureGroupBuildState();
        $collection  = new AssetGroupCollection();
        $contentId   = 'abc-123';
        $groupKey    = '2024-01-01_12-00-00-000';

        $pendingFile                                             = new SplFileInfo('/photos/IMG_0001.mov');
        $state->temporalMetadataMap[$pendingFile->getPathname()] = new TemporalMetadata(
            new DateTimeImmutable('2024-01-01 12:00:00'),
            null,
        );
        $state->contentIdentifierMap[$pendingFile->getPathname()] = $contentId;

        $coordinator->initializeCacheEntry($contentId, $state);
        $state->contentIdentifierCache[$contentId]->addPendingFile($pendingFile);

        $still = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'));

        $coordinator->attachToResolvedGroup(
            $still,
            $groupKey,
            TargetFileResult::success(new SplFileInfo('/photos/2024-01-01_12-00-00-000.heic')),
            $collection,
            $state,
            $contentId,
        );

        $group = $collection->get($groupKey);
        self::assertInstanceOf(AssetGroup::class, $group);
        self::assertCount(2, $group->getItems());
        self::assertSame([], $state->contentIdentifierCache[$contentId]->getPendingFiles());

        $attachedPending = $group->getItemByPath($pendingFile->getPathname());
        self::assertInstanceOf(AssetItem::class, $attachedPending);
        self::assertSame($contentId, $attachedPending->contentIdentifier);
        self::assertInstanceOf(TemporalMetadata::class, $attachedPending->metadata);
    }

    /**
     * Verifies that a skipped file with a resolved content identifier is attached
     * to the already-known group instead of being surfaced as a skipped file.
     */
    #[Test]
    public function skippedFileIsAttachedToResolvedContentIdentifierGroup(): void
    {
        $classifier  = self::createStub(MediaTypeClassifierInterface::class);
        $coordinator = new CaptureContentIdentifierCoordinator($classifier);
        $state       = new CaptureGroupBuildState();
        $collection  = new AssetGroupCollection();
        $context     = new PipelineContext('/photos');
        $contentId   = 'abc-123';
        $groupKey    = '2024-01-01_12-00-00-000';

        $group = new AssetGroup($groupKey);
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.heic')));

        $collection->set($groupKey, $group);

        $coordinator->initializeCacheEntry($contentId, $state);
        $state->contentIdentifierCache[$contentId]->rememberResolvedGroup(
            $groupKey,
            new SplFileInfo('/photos/2024-01-01_12-00-00-000.heic'),
        );
        $state->contentIdentifierMap['/photos/IMG_0001.mov'] = $contentId;

        $coordinator->handleSkippedFile(
            new SplFileInfo('/photos/IMG_0001.mov'),
            TargetFileResult::skipped('no capture date'),
            $collection,
            $context,
            $state,
            $contentId,
        );

        self::assertSame([], $context->getSkippedFiles());
        self::assertInstanceOf(AssetItem::class, $group->getItemByPath('/photos/IMG_0001.mov'));
    }

    /**
     * Verifies that a genuinely unrelated skipped file is still recorded in the
     * pipeline context with its operator-facing skip reason.
     */
    #[Test]
    public function unrelatedSkippedFileIsRecordedInContext(): void
    {
        $classifier  = self::createStub(MediaTypeClassifierInterface::class);
        $coordinator = new CaptureContentIdentifierCoordinator($classifier);
        $state       = new CaptureGroupBuildState();
        $collection  = new AssetGroupCollection();
        $context     = new PipelineContext('/photos');
        $file        = new SplFileInfo('/photos/IMG_9999.mov');

        $coordinator->handleSkippedFile(
            $file,
            TargetFileResult::skipped('no capture date'),
            $collection,
            $context,
            $state,
            null,
        );

        self::assertCount(1, $context->getSkippedFiles());
        self::assertSame($file->getPathname(), $context->getSkippedFiles()[0]->getFile()->getPathname());
    }
}
