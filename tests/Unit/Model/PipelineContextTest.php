<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Model\SkippedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the mutable state-bag contract of PipelineContext.
 *
 * PipelineContext accumulates filesystem state and analysis quality flags
 * across pipeline phases. toRenameResult() converts the accumulated state
 * into an immutable RenameResult for the execution phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PipelineContext::class)]
#[UsesClass(RenameResult::class)]
#[UsesClass(SkippedFile::class)]
#[UsesClass(VideoDuplicateCandidate::class)]
#[UsesClass(OutputEntry::class)]
#[UsesClass(OutputEntryTag::class)]
final class PipelineContextTest extends TestCase
{
    /**
     * Verifies that markOccupied() records a pathname as occupied and that
     * isOccupied() correctly returns true for a marked path and false for
     * an unmarked path.
     *
     * The disk index is used during the assign phase to avoid writing two
     * files to the same target path. A miss here would cause silent overwrites.
     */
    #[Test]
    public function diskIndexTracksOccupiedPaths(): void
    {
        $context = new PipelineContext('/photos');

        $context->markOccupied('/photos/2024-08-31_14-22-08.heic');

        self::assertTrue($context->isOccupied('/photos/2024-08-31_14-22-08.heic'));
        self::assertFalse($context->isOccupied('/photos/2024-08-31_14-22-09.heic'));
    }

    /**
     * Verifies that each quality flag accumulator correctly stores and returns
     * its pathnames, and that unrelated accumulators remain empty.
     *
     * Quality flags are reported in the command summary. If a flag is mis-routed
     * to the wrong accumulator the user sees misleading diagnostic output.
     */
    #[Test]
    public function qualityFlagsTrackPathnames(): void
    {
        $context = new PipelineContext('/photos');

        $context->addFallbackDateFile('/photos/a.jpg');
        $context->addAmbiguousTimezoneFile('/photos/b.mov');
        $context->addLivePhotoConflictFile('/photos/c.heic');
        $context->addSkippedFile(new SkippedFile(new SplFileInfo('/photos/d.heic'), 'no capture date'));
        $context->addVideoDuplicateCandidate(new VideoDuplicateCandidate(
            '/photos/e.mov',
            '/photos/archive/e.mov',
            'video stream identical, audio differs',
        ));

        self::assertSame(['/photos/a.jpg' => true], $context->getFallbackDateFiles());
        self::assertSame(['/photos/b.mov' => true], $context->getAmbiguousTimezoneFiles());
        self::assertSame(['/photos/c.heic' => true], $context->getLivePhotoConflictFiles());
        self::assertCount(1, $context->getSkippedFiles());
        self::assertSame('/photos/d.heic', $context->getSkippedFiles()[0]->getFile()->getPathname());
        self::assertCount(1, $context->getVideoDuplicateCandidates());
        self::assertSame('/photos/archive/e.mov', $context->getVideoDuplicateCandidates()[0]->counterpartPath);
    }

    /**
     * Verifies that the scanned file counter starts at zero, can be set to an
     * arbitrary value, and that the getter returns the stored value.
     *
     * The scanned count is displayed in the summary banner. An incorrect value
     * causes the user to see wrong totals.
     */
    #[Test]
    public function scannedFileCountTracksTotal(): void
    {
        $context = new PipelineContext('/photos');

        self::assertSame(0, $context->getScannedFileCount());

        $context->setScannedFileCount(42);

        self::assertSame(42, $context->getScannedFileCount());
    }

    /**
     * Verifies that incrementNamingCollisions() increases the counter by one
     * per call and that getNamingCollisions() returns the accumulated total.
     *
     * Each collision is a file that required a safe-rename suffix. An
     * under-reported total hides rename conflicts from the user summary.
     */
    #[Test]
    public function namingCollisionsIncrement(): void
    {
        $context = new PipelineContext('/photos');

        self::assertSame(0, $context->getNamingCollisions());

        $context->incrementNamingCollisions();
        $context->incrementNamingCollisions();

        self::assertSame(2, $context->getNamingCollisions());
    }

    /**
     * Verifies that the sourceDirectory constructor argument is stored in the
     * public readonly property and that it survives mutation of other fields.
     *
     * The source directory is used for display and path resolution throughout
     * the pipeline. An incorrect value would cause paths to be misreported.
     */
    #[Test]
    public function sourceDirectoryIsAccessible(): void
    {
        $context = new PipelineContext('/my/photos');

        self::assertSame('/my/photos', $context->sourceDirectory);

        // Mutation of other fields must not affect the readonly property.
        $context->setScannedFileCount(10);
        $context->incrementNamingCollisions();

        self::assertSame('/my/photos', $context->sourceDirectory);
    }

    /**
     * Verifies that toRenameResult() builds an immutable RenameResult carrying
     * all values accumulated in the context — scanned count, collision count,
     * skipped files, and every quality flag set.
     *
     * This conversion is the boundary between the mutable pipeline phase and
     * the immutable execution phase. Any field dropped here would silently
     * omit data from the command summary output.
     */
    #[Test]
    public function toRenameResultConvertsCorrectly(): void
    {
        $context = new PipelineContext('/photos');

        $skipped = new SkippedFile(new SplFileInfo('/photos/bad.heic'), 'no capture date');

        $context->setScannedFileCount(100);
        $context->incrementNamingCollisions();
        $context->incrementNamingCollisions();
        $context->incrementNamingCollisions();
        $context->addSkippedFile($skipped);
        $context->addFallbackDateFile('/photos/a.jpg');
        $context->addAmbiguousTimezoneFile('/photos/b.mov');
        $context->addLivePhotoConflictFile('/photos/c.heic');
        $context->addVideoDuplicateCandidate(new VideoDuplicateCandidate(
            '/photos/e.mov',
            '/photos/archive/e.mov',
            'video stream identical, audio differs',
        ));

        $reviewEntry = OutputEntry::info(
            sortKey: '/photos/e.mov',
            sourcePath: 'e.mov',
            reason: 'Cross-group video review: archive/e.mov — video stream identical, audio differs',
            tag: OutputEntryTag::Review,
        );

        $result = $context->toRenameResult([$reviewEntry], 1);

        self::assertSame(100, $result->scannedFiles);
        self::assertSame(3, $result->namingCollisions);
        self::assertSame([$skipped], $result->skippedFiles);
        self::assertSame(['/photos/a.jpg' => true], $result->fallbackDateFiles);
        self::assertSame(['/photos/b.mov' => true], $result->ambiguousTimezoneFiles);
        self::assertSame(['/photos/c.heic' => true], $result->livePhotoConflictFiles);
        self::assertSame([$reviewEntry], $result->reviewEntries);
        self::assertSame(1, $result->crossGroupVideoReviewCount);
    }
}
