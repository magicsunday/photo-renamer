<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(RenameOutputRenderer::class)]
#[CoversClass(FileDuplicateCollection::class)]
#[CoversClass(FileDuplicate::class)]
#[CoversClass(Rename::class)]
#[CoversClass(RenameOptions::class)]
#[CoversClass(RenameResult::class)]
#[CoversClass(SkippedFile::class)]
/**
 * Verifies the RenameOutputRenderer, which handles building sorted output entry
 * lists, rendering summary statistics, and providing Live Photo identifier queries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RenameOutputRendererTest extends TestCase
{
    /**
     * Verifies that buildOutputEntries returns rename entries sorted by source path
     * and computes the correct max filename length.
     */
    #[Test]
    public function buildOutputEntriesReturnsSortedEntries(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $sourceA = $sourceDir . '/b-image.jpg';
        $targetA = $sourceDir . '/b-image.jpg';
        $sourceB = $sourceDir . '/a-image.jpg';
        $targetB = $sourceDir . '/a-image.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($targetA));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($sourceA), new SplFileInfo($targetA)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($sourceB), new SplFileInfo($targetB)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        [$entries, $maxFilenameLength, $skippedCount, $errorCount] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(),
            $sourceDir,
        );

        self::assertCount(2, $entries);
        // Entries should be sorted by source path: a-image before b-image
        self::assertSame($sourceB, $entries[0]['sortKey']);
        self::assertSame($sourceA, $entries[1]['sortKey']);
        self::assertSame(0, $skippedCount);
        self::assertSame(0, $errorCount);
        self::assertGreaterThan(0, $maxFilenameLength);
    }

    /**
     * Verifies that buildOutputEntries tags duplicate targets with OutputEntryTag::Duplicate.
     */
    #[Test]
    public function buildOutputEntriesTagsDuplicateTargets(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $canonicalTarget = $sourceDir . '/photo.jpg';
        $duplicateSource = $sourceDir . '/photo.jpg';
        $duplicateTarget = $sourceDir . '/photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($canonicalTarget));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($duplicateSource), new SplFileInfo($duplicateTarget)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        [$entries] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(),
            $sourceDir,
        );

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Duplicate, $entries[0]['tag']);
        self::assertTrue($entries[0]['isDuplicateTarget']);
    }

    /**
     * Verifies that buildOutputEntries includes skipped files with correct counts.
     */
    #[Test]
    public function buildOutputEntriesIncludesSkippedFiles(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $collection = new FileDuplicateCollection();

        $result = new RenameResult(
            skippedFiles: [
                new SkippedFile(new SplFileInfo($sourceDir . '/no-date.jpg'), 'no capture date'),
                new SkippedFile(new SplFileInfo($sourceDir . '/error.jpg'), 'read error', true),
            ],
        );

        [$entries, , $skippedCount, $errorCount] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            $result,
            $sourceDir,
        );

        self::assertCount(2, $entries);
        self::assertSame(1, $skippedCount);
        self::assertSame(1, $errorCount);
        // Entries are sorted by source path: error.jpg before no-date.jpg
        self::assertSame(OutputEntryTag::Error, $entries[0]['tag']);
        self::assertSame(OutputEntryTag::Skipped, $entries[1]['tag']);
    }

    /**
     * Verifies that renderSummary outputs the expected labels for all provided counters.
     */
    #[Test]
    public function renderSummaryDisplaysAllNonZeroCounters(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $renderer->renderSummary([
            'scannedFiles'     => 10,
            'skippedCount'     => 2,
            'errorCount'       => 1,
            'livePhotoGroups'  => 3,
            'namingCollisions' => 4,
            'fileCount'        => 5,
            'duplicateCount'   => 6,
            'plannedMoves'     => 7,
            'plannedSkips'     => 8,
        ], false);

        $buffer = $output->fetch();

        self::assertStringContainsString('Summary', $buffer);
        self::assertStringContainsString('Scanned files', $buffer);
        self::assertStringContainsString('Skipped (no metadata)', $buffer);
        self::assertStringContainsString('Skipped (read errors)', $buffer);
        self::assertStringContainsString('Live Photo groups', $buffer);
        self::assertStringContainsString('Naming collisions', $buffer);
        self::assertStringContainsString('Duplicates found', $buffer);
        self::assertStringContainsString('Planned moves', $buffer);
        self::assertStringContainsString('Planned skips', $buffer);
        self::assertStringContainsString('Files processed', $buffer);
    }

    /**
     * Verifies that renderSummary shows "Files to process" instead of "Files processed"
     * when dryRun is true.
     */
    #[Test]
    public function renderSummaryShowsFilesToProcessInDryRun(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $renderer->renderSummary([
            'scannedFiles'     => 5,
            'skippedCount'     => 0,
            'errorCount'       => 0,
            'livePhotoGroups'  => 0,
            'namingCollisions' => 0,
            'fileCount'        => 3,
            'duplicateCount'   => 0,
            'plannedMoves'     => 3,
            'plannedSkips'     => 0,
        ], true);

        $buffer = $output->fetch();

        self::assertStringContainsString('Files to process', $buffer);
        self::assertStringNotContainsString('Files processed', $buffer);
    }

    /**
     * Verifies that renderSummary hides zero-value optional rows.
     */
    #[Test]
    public function renderSummaryHidesZeroCounters(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $renderer->renderSummary([
            'scannedFiles'     => 5,
            'skippedCount'     => 0,
            'errorCount'       => 0,
            'livePhotoGroups'  => 0,
            'namingCollisions' => 0,
            'fileCount'        => 3,
            'duplicateCount'   => 0,
            'plannedMoves'     => 3,
            'plannedSkips'     => 0,
        ], false);

        $buffer = $output->fetch();

        self::assertStringNotContainsString('Skipped (no metadata)', $buffer);
        self::assertStringNotContainsString('Skipped (read errors)', $buffer);
        self::assertStringNotContainsString('Live Photo groups', $buffer);
        self::assertStringNotContainsString('Naming collisions', $buffer);
        self::assertStringNotContainsString('Duplicates found', $buffer);
        self::assertStringNotContainsString('Planned skips', $buffer);
    }

    /**
     * Verifies that isLivePhotoIdentifier returns true for identifiers
     * with the "live-photo:" prefix.
     */
    #[Test]
    public function isLivePhotoIdentifierReturnsTrueForLivePhotoPrefix(): void
    {
        [$renderer] = $this->createRenderer();

        self::assertTrue($renderer->isLivePhotoIdentifier('live-photo:content-id'));
        self::assertTrue($renderer->isLivePhotoIdentifier('live-photo:'));
    }

    /**
     * Verifies that isLivePhotoIdentifier returns false for non-Live Photo identifiers.
     */
    #[Test]
    public function isLivePhotoIdentifierReturnsFalseForOtherIdentifiers(): void
    {
        [$renderer] = $this->createRenderer();

        self::assertFalse($renderer->isLivePhotoIdentifier('some-other-id'));
        self::assertFalse($renderer->isLivePhotoIdentifier(42));
        self::assertFalse($renderer->isLivePhotoIdentifier(''));
    }

    /**
     * Verifies that countLivePhotoGroups counts only groups whose identifier
     * starts with the "live-photo:" prefix.
     */
    #[Test]
    public function countLivePhotoGroupsCountsCorrectly(): void
    {
        [$renderer] = $this->createRenderer();

        $livePhotoDuplicate = new FileDuplicate();
        $livePhotoDuplicate->setTarget(new SplFileInfo('/tmp/photo.jpg'));

        $normalDuplicate = new FileDuplicate();
        $normalDuplicate->setTarget(new SplFileInfo('/tmp/other.jpg'));

        $collection = new FileDuplicateCollection();
        $collection->set('live-photo:content-id-1', $livePhotoDuplicate);
        $collection->set('live-photo:content-id-2', $livePhotoDuplicate);
        $collection->set('normal-group', $normalDuplicate);

        self::assertSame(2, $renderer->countLivePhotoGroups($collection));
    }

    /**
     * Verifies that countTotalOperations sums all rename operations across all groups.
     */
    #[Test]
    public function countTotalOperationsCountsAllRenames(): void
    {
        [$renderer] = $this->createRenderer();

        $fileDuplicate1 = new FileDuplicate();
        $fileDuplicate1->setTarget(new SplFileInfo('/tmp/a.jpg'));
        $fileDuplicate1->addRename(new Rename(new SplFileInfo('/tmp/src/a1.jpg'), new SplFileInfo('/tmp/a.jpg')));
        $fileDuplicate1->addRename(new Rename(new SplFileInfo('/tmp/src/a2.jpg'), new SplFileInfo('/tmp/a' . Constants::DUPLICATE_IDENTIFIER . '001.jpg')));

        $fileDuplicate2 = new FileDuplicate();
        $fileDuplicate2->setTarget(new SplFileInfo('/tmp/b.jpg'));
        $fileDuplicate2->addRename(new Rename(new SplFileInfo('/tmp/src/b1.jpg'), new SplFileInfo('/tmp/b.jpg')));

        $collection = new FileDuplicateCollection();
        $collection->set('group-a', $fileDuplicate1);
        $collection->set('group-b', $fileDuplicate2);

        self::assertSame(3, $renderer->countTotalOperations($collection));
    }

    /**
     * Verifies that a rename entry with a large date drift between source and target
     * filenames is tagged as Warning and marked for skipping.
     */
    #[Test]
    public function buildOutputEntriesTagsWarningOnLargeDateDrift(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $source = $sourceDir . '/2024-03-30_12-16-24.mov';
        $target = $sourceDir . '/2024-09-19_02-21-38-000.mov';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        [$entries] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        );

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]['tag']);
        self::assertTrue($entries[0]['shouldSkip']);
    }

    /**
     * Verifies that a rename entry with matching dates in source and target
     * is NOT tagged as Warning even when date drift checking is enabled.
     */
    #[Test]
    public function buildOutputEntriesDoesNotWarnOnSameDate(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $source = $sourceDir . '/2024-03-30_12-16-24.jpg';
        $target = $sourceDir . '/2024-03-30_12-16-24-000.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        [$entries] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        );

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Rename, $entries[0]['tag']);
        self::assertFalse($entries[0]['shouldSkip']);
    }

    /**
     * Verifies that a rename entry where the source has no recognizable date
     * is NOT tagged as Warning (drift cannot be computed).
     */
    #[Test]
    public function buildOutputEntriesDoesNotWarnWhenSourceHasNoDate(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $source = $sourceDir . '/IMG_1234.jpg';
        $target = $sourceDir . '/2024-03-30_12-16-24.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        [$entries] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        );

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Rename, $entries[0]['tag']);
        self::assertFalse($entries[0]['shouldSkip']);
    }

    /**
     * Verifies that date drift checking is disabled when maxDateDrift is 0.
     */
    #[Test]
    public function buildOutputEntriesSkipsDriftCheckWhenDisabled(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $source = $sourceDir . '/2024-03-30_12-16-24.mov';
        $target = $sourceDir . '/2024-09-19_02-21-38-000.mov';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        [$entries] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 0),
            new RenameResult(),
            $sourceDir,
        );

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Rename, $entries[0]['tag']);
        self::assertFalse($entries[0]['shouldSkip']);
    }

    /**
     * Verifies that compact YYYYMMDD date patterns (e.g. IMG_20240330_121624.jpg)
     * are recognized for date drift detection.
     */
    #[Test]
    public function buildOutputEntriesRecognizesCompactDatePattern(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $source = $sourceDir . '/IMG_20240330_121624.jpg';
        $target = $sourceDir . '/2024-09-19_02-21-38-000.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        [$entries] = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        );

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]['tag']);
        self::assertTrue($entries[0]['shouldSkip']);
    }

    /**
     * @return array{RenameOutputRenderer, BufferedOutput}
     */
    private function createRenderer(): array
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        return [new RenameOutputRenderer($io), $output];
    }
}
