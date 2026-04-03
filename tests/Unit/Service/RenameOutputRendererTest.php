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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionPreview;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\OutputEntryType;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Service\Output\DiffHighlighter;
use MagicSunday\Renamer\Service\Output\DiffTokenState;
use MagicSunday\Renamer\Service\Output\OutputCounters;
use MagicSunday\Renamer\Service\Output\OutputDecisionLogRenderer;
use MagicSunday\Renamer\Service\Output\OutputEntryBuildResult;
use MagicSunday\Renamer\Service\Output\OutputEntryPresenter;
use MagicSunday\Renamer\Service\Output\OutputSkipReason;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecider;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecision;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\CandidateOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\DefaultOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\FallbackOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\ReviewOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\WarningOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSummaryRowBuilder;
use MagicSunday\Renamer\Service\Output\SkipReasonFormatter;
use MagicSunday\Renamer\Service\Output\SummaryRow;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
#[UsesClass(LinkConfig::class)]
#[UsesClass(OutputEntry::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(OutputEntryType::class)]
#[UsesClass(ExecutionGroup::class)]
#[UsesClass(ExecutionItem::class)]
#[UsesClass(ExecutionPlan::class)]
#[UsesClass(ExecutionPreview::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(DiffHighlighter::class)]
#[UsesClass(DiffTokenState::class)]
#[UsesClass(OutputCounters::class)]
#[UsesClass(OutputDecisionLogRenderer::class)]
#[UsesClass(OutputEntryBuildResult::class)]
#[UsesClass(OutputEntryPresenter::class)]
#[UsesClass(OutputSkipReason::class)]
#[UsesClass(OutputSkipReasonDecider::class)]
#[UsesClass(OutputSkipReasonDecision::class)]
#[UsesClass(CandidateOutputSkipReasonRule::class)]
#[UsesClass(DefaultOutputSkipReasonRule::class)]
#[UsesClass(FallbackOutputSkipReasonRule::class)]
#[UsesClass(ReviewOutputSkipReasonRule::class)]
#[UsesClass(WarningOutputSkipReasonRule::class)]
#[UsesClass(OutputSummaryRowBuilder::class)]
#[UsesClass(SkipReasonFormatter::class)]
#[UsesClass(SummaryRow::class)]
final class RenameOutputRendererTest extends TestCase
{
    /**
     * Verifies that buildOutputEntries returns rename entries sorted alphabetically
     * by their source pathname.
     *
     * Stable sorting ensures consistent console output even if the underlying
     * filesystem or hash-map iteration order varies. This test checks that a file
     * starting with 'a-' appears before one starting with 'b-'.
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

        $buildResult = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(),
            $sourceDir,
        );
        $entries      = $buildResult->entries;
        $skippedCount = $buildResult->skippedCount;
        $errorCount   = $buildResult->errorCount;

        self::assertCount(2, $entries);
        // Entries should be sorted by source path: a-image before b-image
        self::assertSame($sourceB, $entries[0]->sortKey);
        self::assertSame($sourceA, $entries[1]->sortKey);
        self::assertSame(0, $skippedCount);
        self::assertSame(0, $errorCount);
    }

    /**
     * Verifies that buildOutputEntries identifies and tags duplicate target paths.
     *
     * A duplicate target occurs when multiple source files (e.g., exact copies)
     * would be renamed to the same destination filename. The renderer must mark
     * subsequent files targeting the same path with OutputEntryTag::Duplicate
     * to visually distinguish them from the primary file.
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

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(2, $entries);
        self::assertSame(OutputEntryTag::Duplicate, $entries[0]->tag);
        self::assertTrue($entries[0]->isDuplicateTarget);
        self::assertTrue($entries[1]->isInfo());
        self::assertStringContainsString('Duplicate of', $entries[1]->reason ?? '');
    }

    /**
     * Verifies that legacy duplicate companion videos reference the matching
     * non-duplicate video target instead of the canonical still target.
     *
     * The legacy rename path still renders duplicate info lines through
     * buildOutputEntries(). When a Live Photo group contains a canonical still,
     * a non-duplicate MOV companion, and a duplicate MOV target, the info line
     * must point at the unsuffixed MOV target so the operator sees the correct
     * media-equivalent reference.
     */
    #[Test]
    public function legacyDuplicateCompanionUsesMatchingExtensionReferenceTarget(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($sourceDir . '/2025-01-01_10-00-00-000.jpg'));
        $fileDuplicate->addRename(new Rename(
            new SplFileInfo($sourceDir . '/still.jpg'),
            new SplFileInfo($sourceDir . '/2025-01-01_10-00-00-000.jpg'),
        ));
        $fileDuplicate->addRename(new Rename(
            new SplFileInfo($sourceDir . '/primary.mov'),
            new SplFileInfo($sourceDir . '/2025-01-01_10-00-00-000.mov'),
        ));
        $fileDuplicate->addRename(new Rename(
            new SplFileInfo($sourceDir . '/duplicate.mov'),
            new SplFileInfo($sourceDir . '/2025-01-01_10-00-00-000' . Constants::DUPLICATE_IDENTIFIER . '001.mov'),
        ));

        $collection = new FileDuplicateCollection();
        $collection->set('live-photo:cid-1', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(4, $entries);

        $duplicateEntry = null;
        $infoEntry      = null;

        foreach ($entries as $entry) {
            if (($entry->sourcePath === 'duplicate.mov') && ($entry->type === OutputEntryType::Rename)) {
                $duplicateEntry = $entry;
            }

            if (($entry->sourcePath === 'duplicate.mov') && ($entry->type === OutputEntryType::Info)) {
                $infoEntry = $entry;
            }
        }

        self::assertNotNull($duplicateEntry);
        self::assertNotNull($infoEntry);
        self::assertSame(OutputEntryTag::Duplicate, $duplicateEntry->tag);
        self::assertSame('Duplicate of 2025-01-01_10-00-00-000.mov', $infoEntry->reason);
    }

    /**
     * Verifies that Live Photo content identifier conflicts are correctly identified
     * and tagged as candidates for inspection.
     *
     * When a photo and video appear to be a pair but have mismatched content IDs,
     * they are tagged as OutputEntryTag::Candidate. This test ensures the renderer
     * detects this state from the RenameResult and assigns the appropriate tag.
     */
    #[Test]
    public function buildOutputEntriesTagsLivePhotoContentIdConflictsAsCandidates(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $source = $sourceDir . '/photo.mov';
        $target = $sourceDir . '/2025-01-01_00-02-20-016.mov';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(
                livePhotoConflictFiles: [$source => true],
            ),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Candidate, $entries[0]->tag);
        self::assertTrue($entries[0]->shouldSkip);
    }

    /**
     * Verifies that files skipped during the scanning or grouping phase are included
     * in the output list with their specific skip reasons.
     *
     * Reporting skipped files is essential for transparency, allowing the user to
     * understand why certain files in the directory were not processed (e.g. missing
     * EXIF data or unmapped extension).
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

        $buildResult = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            $result,
            $sourceDir,
        );
        $entries      = $buildResult->entries;
        $skippedCount = $buildResult->skippedCount;
        $errorCount   = $buildResult->errorCount;

        self::assertCount(2, $entries);
        self::assertSame(1, $skippedCount);
        self::assertSame(1, $errorCount);
        // Entries are sorted by source path: error.jpg before no-date.jpg
        self::assertSame(OutputEntryTag::Error, $entries[0]->tag);
        self::assertSame(OutputEntryTag::Skipped, $entries[1]->tag);
    }

    /**
     * Verifies that info-only notices respect the --show filter and do not leak into
     * duplicate-only output as orphaned continuation lines.
     *
     * Cross-directory Live Photo notices are tagged as Info, not Duplicate. When the
     * user requests only [D] entries, these notices must stay hidden.
     */
    #[Test]
    public function renderEntryLinesHidesInfoEntriesWhenShowFilterExcludesThem(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $entries = [
            OutputEntry::info(
                sortKey: '/tmp/source/2019/companion.mov',
                sourcePath: '2019/companion.mov',
                reason: 'Live Photo pair across directories: <fg=cyan>2019/canonical.jpg</>',
            ),
        ];

        $renderer->renderEntryLines($entries, '/tmp/source', ['D']);

        self::assertSame('', $output->fetch());
    }

    /**
     * Verifies that visible info entries render as a two-line block when their anchor
     * entry is not visible in the current filter result.
     *
     * This keeps `--show=I` usable for standalone notices such as cross-directory
     * Live Photo pair reports.
     */
    #[Test]
    public function renderEntryLinesShowsTwoLineInfoBlockWithoutVisibleAnchor(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $entries = [
            OutputEntry::info(
                sortKey: '/tmp/source/2019/companion.mov',
                sourcePath: '2019/companion.mov',
                reason: 'Live Photo pair across directories: <fg=cyan>2019/canonical.jpg</>',
            ),
        ];

        $renderer->renderEntryLines($entries, '/tmp/source', ['I']);

        $buffer = $output->fetch();

        self::assertStringContainsString('[I]', $buffer);
        self::assertStringContainsString('2019/companion.mov', $buffer);
        self::assertStringContainsString("\n      → ", $buffer);
        self::assertStringContainsString('Live Photo pair across directories: 2019/canonical.jpg', $buffer);
    }

    /**
     * Verifies that skipped warning entries also use the two-line block layout so
     * long explanations do not continue on the same line as the source path.
     */
    #[Test]
    public function renderEntryLinesShowsTwoLineWarningBlock(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $entries = [
            OutputEntry::rename(
                sortKey: '/tmp/source/2019/clip.mov',
                sourcePath: '2019/clip.mov',
                targetPath: '2019/clip.mov',
                tag: OutputEntryTag::Warning,
                shouldSkip: true,
                warningReason: 'Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone',
            ),
        ];

        $renderer->renderEntryLines($entries, '/tmp/source', ['W']);

        $buffer = $output->fetch();

        self::assertStringContainsString('[W]', $buffer);
        self::assertStringContainsString('2019/clip.mov', $buffer);
        self::assertStringContainsString("\n      → ", $buffer);
        self::assertStringContainsString('Ambiguous timezone: QuickTime UTC without offset', $buffer);
    }

    /**
     * Verifies that the summary table includes all non-zero operational counters
     * like total renames, duplicates, and collisions.
     *
     * The summary provides a high-level overview of the work performed. This test
     * ensures that when these events occur, they are formatted into the summary
     * section of the console output.
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

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]->tag);
        self::assertTrue($entries[0]->shouldSkip);
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

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Rename, $entries[0]->tag);
        self::assertFalse($entries[0]->shouldSkip);
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

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Rename, $entries[0]->tag);
        self::assertFalse($entries[0]->shouldSkip);
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

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 0),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Rename, $entries[0]->tag);
        self::assertFalse($entries[0]->shouldSkip);
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

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 30),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]->tag);
        self::assertTrue($entries[0]->shouldSkip);
    }

    /**
     * Verifies that highlightDiff produces both base-color and bright-color
     * ANSI codes when source and target differ.
     */
    #[Test]
    public function highlightDiffProducesColorCodesForChangedRegion(): void
    {
        [$renderer] = $this->createRenderer();

        $source = '2021-01-01_00-19-23-8313.mov';
        $target = '2021-01-01_00-19-23-000.mov';

        $result = $renderer->highlightDiff($source, $target, 'green');

        // Result must contain both base-color and highlight-color Symfony tags
        self::assertStringContainsString('<fg=green>', $result, 'Must contain base green color tag');
        self::assertStringContainsString('<fg=bright-green;options=bold>', $result, 'Must contain bright-green highlight tag');

        // Verify that Symfony Console renders both ANSI codes
        $formatter = new OutputFormatter(true);
        $rendered  = (string) $formatter->format($result);

        // ANSI green (32m) for unchanged parts
        self::assertStringContainsString("\033[32m", $rendered, 'Rendered output must contain ANSI green');

        // ANSI bright-green + bold (92;1m) for changed parts
        self::assertStringContainsString("\033[92;1m", $rendered, 'Rendered output must contain ANSI bright-green+bold');

        // The changed region (000) must appear in the output
        self::assertStringContainsString('000', $rendered);
    }

    /**
     * Verifies that highlightDiff returns plain base-color when source equals target.
     */
    #[Test]
    public function highlightDiffReturnsBaseColorWhenIdentical(): void
    {
        [$renderer] = $this->createRenderer();

        $path   = '2021-01-01_00-19-23-000.mov';
        $result = $renderer->highlightDiff($path, $path, 'green');

        self::assertSame('<fg=green>' . $path . '</>', $result);
    }

    /**
     * Verifies highlightDiff for completely different strings.
     */
    #[Test]
    public function highlightDiffHighlightsEntireStringWhenCompletelyDifferent(): void
    {
        [$renderer] = $this->createRenderer();

        $result = $renderer->highlightDiff('abc.jpg', 'xyz.mov', 'green');

        self::assertStringContainsString('<fg=bright-green;options=bold>', $result);
        self::assertStringContainsString('xyz.mov', $result);
    }

    /**
     * Verifies that highlightDiff handles empty target string.
     */
    #[Test]
    public function highlightDiffHandlesEmptyTarget(): void
    {
        [$renderer] = $this->createRenderer();

        $result = $renderer->highlightDiff('photo.jpg', '', 'green');

        self::assertSame('<fg=green></>', $result);
    }

    /**
     * Verifies that highlightDiff correctly highlights directory prefix changes.
     */
    #[Test]
    public function highlightDiffHighlightsDirectoryChange(): void
    {
        [$renderer] = $this->createRenderer();

        $result = $renderer->highlightDiff(
            'original.jpg',
            'backup/2024-12-01_09-00-00-000-duplicate-001.jpg',
            'green',
        );

        self::assertStringContainsString('<fg=bright-green;options=bold>', $result);
    }

    /**
     * Verifies that highlightDiff handles single-character differences.
     */
    #[Test]
    public function highlightDiffHighlightsSingleCharDifference(): void
    {
        [$renderer] = $this->createRenderer();

        $result = $renderer->highlightDiff(
            '2024-01-01_10-00-00-000.jpg',
            '2024-01-01_10-00-00-001.jpg',
            'cyan',
        );

        self::assertStringContainsString('<fg=bright-cyan;options=bold>', $result);
        self::assertStringContainsString('<fg=cyan>', $result);
    }

    /**
     * Verifies that ambiguous timezone files with duplicate status get [W] tag.
     */
    #[Test]
    public function buildOutputEntriesTagsWarningForDuplicateWithAmbiguousTimezone(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $canonical       = $sourceDir . '/clip-a.mp4';
        $duplicate       = $sourceDir . '/clip-b.mp4';
        $canonicalTarget = $sourceDir . '/2025-06-10_16-30-00-000.mp4';
        $duplicateTarget = $sourceDir . '/2025-06-10_16-30-00-000' . Constants::DUPLICATE_IDENTIFIER . '001.mp4';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($canonicalTarget));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($canonical), new SplFileInfo($canonicalTarget)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($duplicate), new SplFileInfo($duplicateTarget)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(
                ambiguousTimezoneFiles: [$canonical => true, $duplicate => true],
            ),
            $sourceDir,
        )->entries;

        self::assertCount(2, $entries);
        // Both should be [W], not [D]
        self::assertSame(OutputEntryTag::Warning, $entries[0]->tag);
        self::assertSame(OutputEntryTag::Warning, $entries[1]->tag);
        self::assertTrue($entries[0]->shouldSkip);
        self::assertTrue($entries[1]->shouldSkip);
    }

    /**
     * Verifies that the warningReason field contains the date drift details.
     */
    #[Test]
    public function buildOutputEntriesIncludesDriftDetailsInWarningReason(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';

        $source = $sourceDir . '/2024-01-15_10-00-00.jpg';
        $target = $sourceDir . '/2024-06-20_10-00-00-000.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(maxDateDrift: 7),
            new RenameResult(),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]->tag);

        /** @var string $warningReason */
        $warningReason = $entries[0]->warningReason;
        self::assertStringContainsString('Date drift:', $warningReason);
        self::assertStringContainsString('max 7', $warningReason);
    }

    /**
     * Verifies renderSummarySection produces aligned output.
     */
    #[Test]
    public function renderSummarySectionAlignedOutput(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $rows = [
            new SummaryRow('Short', '10'),
            new SummaryRow('Much longer label', '200'),
        ];

        $renderer->renderSummarySection($rows, new SymfonyStyle(new ArrayInput([]), $output));

        $buffer = $output->fetch();

        self::assertStringContainsString('Short', $buffer);
        self::assertStringContainsString('Much longer label', $buffer);
        self::assertStringContainsString('10', $buffer);
        self::assertStringContainsString('200', $buffer);
    }

    /**
     * Verifies that fallback date files are tagged as Fallback.
     */
    #[Test]
    public function buildOutputEntriesTagsFallbackDateFiles(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';
        $source    = $sourceDir . '/scan.jpg';
        $target    = $sourceDir . '/2024-01-01_00-00-00-000.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(
                fallbackDateFiles: [$source => true],
            ),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Fallback, $entries[0]->tag);
    }

    /**
     * Verifies that fallback date entries in the legacy path are tagged [F]
     * but NOT skipped (execution blocking is handled by isExecutable in the
     * plan path, not the legacy path).
     */
    #[Test]
    public function buildOutputEntriesFallbackNotSkippedInLegacyPath(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';
        $source    = $sourceDir . '/scan.jpg';
        $target    = $sourceDir . '/2024-01-01_00-00-00-000.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(
                fallbackDateFiles: [$source => true],
            ),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Fallback, $entries[0]->tag);
        self::assertFalse($entries[0]->shouldSkip);
    }

    /**
     * Verifies that a duplicate with fallback date is tagged [F], not [D].
     * Fix 9: [F] has higher priority than [D] in the tag chain.
     */
    #[Test]
    public function buildOutputEntriesTagsFallbackOverDuplicateForFallbackDuplicate(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir       = '/tmp/source';
        $canonical       = $sourceDir . '/photo-a.jpg';
        $duplicate       = $sourceDir . '/photo-b.jpg';
        $canonicalTarget = $sourceDir . '/2025-01-01_12-00-00-000.jpg';
        $duplicateTarget = $sourceDir . '/2025-01-01_12-00-00-000' . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($canonicalTarget));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($canonical), new SplFileInfo($canonicalTarget)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($duplicate), new SplFileInfo($duplicateTarget)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(
                fallbackDateFiles: [$duplicate => true],
            ),
            $sourceDir,
        )->entries;

        self::assertCount(2, $entries);

        // The duplicate entry (photo-b) must be [F], not [D].
        $duplicateEntry = $entries[0]->sourcePath === 'photo-b.jpg' ? $entries[0] : $entries[1];
        self::assertSame(OutputEntryTag::Fallback, $duplicateEntry->tag);
    }

    /**
     * Verifies that a non-duplicate rename with ambiguous timezone is tagged [W].
     * Fix 9: [W] applies to all renames, not just duplicates.
     */
    #[Test]
    public function buildOutputEntriesTagsWarningForNonDuplicateRenameWithAmbiguousTimezone(): void
    {
        [$renderer] = $this->createRenderer();

        $sourceDir = '/tmp/source';
        $source    = $sourceDir . '/clip.mp4';
        $target    = $sourceDir . '/2025-06-10_16-30-00-000.mp4';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($source), new SplFileInfo($target)));

        $collection = new FileDuplicateCollection();
        $collection->set('test', $fileDuplicate);

        $entries = $renderer->buildOutputEntries(
            $collection,
            new RenameOptions(),
            new RenameResult(
                ambiguousTimezoneFiles: [$source => true],
            ),
            $sourceDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]->tag);
        self::assertTrue($entries[0]->shouldSkip);
    }

    // ---------------------------------------------------------------
    //  ExecutionPlan rendering tests
    // ---------------------------------------------------------------

    /**
     * Verifies that buildOutputEntriesFromPlan maps each quality flag
     * to the correct OutputEntryTag.
     */
    #[Test]
    public function buildOutputEntriesFromPlanAssignsCorrectTags(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-rename', false, null, [
                new ExecutionItem(
                    $baseDir . '/img.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-rename',
                ),
            ]),
            new ExecutionGroup('group-conflict', false, null, [
                new ExecutionItem(
                    $baseDir . '/conflict.mov',
                    $baseDir . '/2025-01-01_10-00-00-000.mov',
                    ExecutionItemType::Companion,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-conflict',
                    isLivePhotoConflict: true,
                    isExecutable: false,
                    executionBlockReason: 'Live Photo conflict',
                ),
            ]),
            new ExecutionGroup('group-warn', false, null, [
                new ExecutionItem(
                    $baseDir . '/clip.mp4',
                    $baseDir . '/2025-06-10_16-30-00-000.mp4',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-warn',
                    isAmbiguousTimezone: true,
                    isExecutable: false,
                    executionBlockReason: 'Ambiguous timezone',
                ),
            ]),
            new ExecutionGroup('group-fallback', false, null, [
                new ExecutionItem(
                    $baseDir . '/scan.jpg',
                    $baseDir . '/2024-01-01_00-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-fallback',
                    isFallbackDate: true,
                    isExecutable: false,
                    executionBlockReason: 'Fallback date',
                ),
            ]),
            new ExecutionGroup('group-dup', false, null, [
                new ExecutionItem(
                    $baseDir . '/dup.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000' . Constants::DUPLICATE_IDENTIFIER . '001.jpg',
                    ExecutionItemType::Duplicate,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-dup',
                    isDuplicateTarget: true,
                ),
            ]),
            new ExecutionGroup('group-noop', false, null, [
                new ExecutionItem(
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: false,
                    isNoOp: true,
                    groupKey: 'group-noop',
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            new RenameResult(),
            $baseDir,
        )->entries;

        // Sorted by source path: 2025-01-01 (noop), clip, conflict, dup, img, scan
        /** @var array<string, OutputEntryTag> $tagMap */
        $tagMap = [];

        foreach ($entries as $entry) {
            /** @var string $sourcePath */
            $sourcePath          = $entry->sourcePath;
            $tagMap[$sourcePath] = $entry->tag;
        }

        self::assertSame(OutputEntryTag::Rename, $tagMap['img.jpg']);
        self::assertSame(OutputEntryTag::Candidate, $tagMap['conflict.mov']);
        self::assertSame(OutputEntryTag::Warning, $tagMap['clip.mp4']);
        self::assertSame(OutputEntryTag::Fallback, $tagMap['scan.jpg']);
        self::assertSame(OutputEntryTag::Duplicate, $tagMap['dup.jpg']);
        self::assertSame(OutputEntryTag::Original, $tagMap['2025-01-01_10-00-00-000.jpg']);
    }

    /**
     * Verifies that buildOutputEntriesFromPlan computes correct counters
     * for skipped/error entries from RenameResult.
     */
    #[Test]
    public function buildOutputEntriesFromPlanCountsCorrectly(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/a.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-a',
                ),
                new ExecutionItem(
                    $baseDir . '/b.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000' . Constants::DUPLICATE_IDENTIFIER . '001.jpg',
                    ExecutionItemType::Duplicate,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-a',
                    isDuplicateTarget: true,
                ),
            ]),
        ]);

        $result = new RenameResult(
            skippedFiles: [
                new SkippedFile(new SplFileInfo($baseDir . '/no-date.jpg'), 'no capture date'),
                new SkippedFile(new SplFileInfo($baseDir . '/broken.jpg'), 'read error', true),
            ],
        );

        $buildResult = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            $result,
            $baseDir,
        );
        $entries      = $buildResult->entries;
        $skippedCount = $buildResult->skippedCount;
        $errorCount   = $buildResult->errorCount;

        // 2 items from plan + 1 duplicate-info line + 2 skipped files = 5 entries
        self::assertCount(5, $entries);
        self::assertSame(1, $skippedCount);
        self::assertSame(1, $errorCount);
    }

    /**
     * Verifies that renderPlanEntries respects the showFilter parameter.
     */
    #[Test]
    public function renderPlanEntriesRespectsShowFilter(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/a.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-a',
                ),
            ]),
            new ExecutionGroup('group-b', false, null, [
                new ExecutionItem(
                    $baseDir . '/2025-02-01_10-00-00-000.jpg',
                    $baseDir . '/2025-02-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: false,
                    isNoOp: true,
                    groupKey: 'group-b',
                ),
            ]),
        ]);

        // Only show [R] entries — the [O] no-op should not render
        $preview = $renderer->renderPlanEntries(
            $plan,
            new RenameOptions(),
            $baseDir,
            ['R'],
        );

        $buffer = $output->fetch();

        self::assertStringContainsString('a.jpg', $buffer);
        self::assertStringNotContainsString('2025-02-01', $buffer);
        self::assertSame(1, $preview->plannedMoves);
    }

    /**
     * Verifies countLivePhotoGroupsInPlan returns the correct count.
     */
    #[Test]
    public function livePhotoGroupCountFromPlan(): void
    {
        [$renderer] = $this->createRenderer();

        $plan = new ExecutionPlan([
            new ExecutionGroup('live-photo:cid-1', true, null, [
                new ExecutionItem(
                    '/tmp/a.heic',
                    '/tmp/2025-01-01_10-00-00-000.heic',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'live-photo:cid-1',
                ),
            ]),
            new ExecutionGroup('live-photo:cid-2', true, null, [
                new ExecutionItem(
                    '/tmp/b.heic',
                    '/tmp/2025-02-01_10-00-00-000.heic',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'live-photo:cid-2',
                ),
            ]),
            new ExecutionGroup('non-live', false, null, [
                new ExecutionItem(
                    '/tmp/c.jpg',
                    '/tmp/2025-03-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'non-live',
                ),
            ]),
        ]);

        self::assertSame(2, $renderer->countLivePhotoGroupsInPlan($plan));
    }

    /**
     * Verifies that a no-op item is tagged as Original.
     */
    #[Test]
    public function noOpItemGetsOriginalTag(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: false,
                    isNoOp: true,
                    groupKey: 'group-a',
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            new RenameResult(),
            $baseDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Original, $entries[0]->tag);
    }

    /**
     * Verifies that a duplicate-target item is tagged as Duplicate.
     */
    #[Test]
    public function duplicateItemGetsDuplicateTag(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/photo-b.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000' . Constants::DUPLICATE_IDENTIFIER . '001.jpg',
                    ExecutionItemType::Duplicate,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-a',
                    isDuplicateTarget: true,
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            new RenameResult(),
            $baseDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Duplicate, $entries[0]->tag);
        self::assertTrue($entries[0]->isDuplicateTarget);
    }

    /**
     * Verifies that duplicate companion videos reference the matching non-duplicate
     * video target, not the canonical still target, in the "Duplicate of ..." info line.
     *
     * Live Photo groups can contain a canonical still plus a non-duplicate companion
     * MOV. When another MOV in the same group becomes a duplicate target, the operator
     * expects the explanatory info line to point at the unsuffixed MOV target rather
     * than at the canonical JPG/HEIC target of the group.
     */
    #[Test]
    public function duplicateCompanionUsesMatchingExtensionReferenceTarget(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('live-photo:cid-1', true, null, [
                new ExecutionItem(
                    $baseDir . '/still.jpg',
                    $baseDir . '/2025-01-01_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'live-photo:cid-1',
                ),
                new ExecutionItem(
                    $baseDir . '/primary.mov',
                    $baseDir . '/2025-01-01_10-00-00-000.mov',
                    ExecutionItemType::Companion,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'live-photo:cid-1',
                ),
                new ExecutionItem(
                    $baseDir . '/duplicate.mov',
                    $baseDir . '/2025-01-01_10-00-00-000' . Constants::DUPLICATE_IDENTIFIER . '001.mov',
                    ExecutionItemType::Duplicate,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'live-photo:cid-1',
                    isDuplicateTarget: true,
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            new RenameResult(),
            $baseDir,
        )->entries;

        self::assertCount(4, $entries);

        $duplicateEntry = null;
        $infoEntry      = null;

        foreach ($entries as $entry) {
            if (($entry->sourcePath === 'duplicate.mov') && ($entry->type === OutputEntryType::Rename)) {
                $duplicateEntry = $entry;
            }

            if (($entry->sourcePath === 'duplicate.mov') && ($entry->type === OutputEntryType::Info)) {
                $infoEntry = $entry;
            }
        }

        self::assertNotNull($duplicateEntry);
        self::assertNotNull($infoEntry);
        self::assertSame(OutputEntryTag::Duplicate, $duplicateEntry->tag);
        self::assertSame('Duplicate of 2025-01-01_10-00-00-000.mov', $infoEntry->reason);
    }

    /**
     * Verifies that a fallback-date item is tagged as Fallback.
     */
    #[Test]
    public function fallbackDateItemGetsFallbackTag(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/scan.jpg',
                    $baseDir . '/2024-01-01_00-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-a',
                    isFallbackDate: true,
                    isExecutable: false,
                    executionBlockReason: 'Fallback date',
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            new RenameResult(),
            $baseDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Fallback, $entries[0]->tag);
    }

    /**
     * Verifies that an ambiguous-timezone item is tagged as Warning.
     */
    #[Test]
    public function ambiguousTimezoneItemGetsWarningTag(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/clip.mp4',
                    $baseDir . '/2025-06-10_16-30-00-000.mp4',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-a',
                    isAmbiguousTimezone: true,
                    isExecutable: false,
                    executionBlockReason: 'Ambiguous timezone: QuickTime UTC without offset',
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            new RenameResult(),
            $baseDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]->tag);
        self::assertTrue($entries[0]->shouldSkip);
    }

    /**
     * Verifies that buildOutputEntriesFromPlan appends mapped review entries from
     * RenameResult instead of requiring a separate renderer path for Feature Track A.
     *
     * The cross-group video review track deliberately reuses the central renderer.
     * This test ensures the output boundary keeps those review entries visible with
     * the dedicated Review tag.
     */
    #[Test]
    public function buildOutputEntriesFromPlanAppendsReviewEntries(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $entries = $renderer->buildOutputEntriesFromPlan(
            new ExecutionPlan([]),
            new RenameOptions(),
            new RenameResult(
                reviewEntries: [
                    OutputEntry::info(
                        sortKey: $baseDir . '/clip.mov',
                        sourcePath: 'clip.mov',
                        reason: 'Cross-group video review: archive/clip.mov — video stream identical, audio differs',
                        tag: OutputEntryTag::Review,
                    ),
                ],
                crossGroupVideoReviewCount: 1,
            ),
            $baseDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertTrue($entries[0]->isInfo());
        self::assertSame(OutputEntryTag::Review, $entries[0]->tag);
    }

    /**
     * Verifies that renderPlanSummary shows the dedicated cross-group video review
     * counter instead of hiding these findings inside a generic summary bucket.
     *
     * The summary line keeps review-only video matches operationally visible even
     * when the inline review entries scroll out of view in large runs.
     */
    #[Test]
    public function renderPlanSummaryIncludesCrossGroupVideoReviewCount(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $renderer->renderPlanSummary(
            new ExecutionPlan([]),
            new RenameResult(
                scannedFiles: 12,
                crossGroupVideoReviewCount: 2,
            ),
            new ExecutionPreview(
                plannedMoves: 0,
                plannedSkips: 0,
                duplicateCount: 0,
            ),
            true,
        );

        $buffer = $output->fetch();

        self::assertStringContainsString('Cross-group video review', $buffer);
        self::assertStringContainsString('2', $buffer);
    }

    /**
     * Verifies that renderDecisionLogFromPlan outputs decision log entries.
     */
    #[Test]
    public function renderDecisionLogFromPlanOutputsEntries(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [], [
                'Canonical selected: a.heic (format priority)',
                'Companion paired: a.mov (content-id match)',
            ]),
            new ExecutionGroup('group-b', false, null, [], []),
        ]);

        $renderer->renderDecisionLogFromPlan($plan);

        $buffer = $output->fetch();

        self::assertStringContainsString('Decision Log', $buffer);
        self::assertStringContainsString('group-a', $buffer);
        self::assertStringContainsString('Canonical selected: a.heic (format priority)', $buffer);
        self::assertStringContainsString('Companion paired: a.mov (content-id match)', $buffer);
        // group-b has no log entries, so it should not appear
        self::assertStringNotContainsString('group-b', $buffer);
    }

    /**
     * Verifies that renderDecisionLogFromPlan produces no output when no groups have logs.
     */
    #[Test]
    public function renderDecisionLogFromPlanNoOutputWhenEmpty(): void
    {
        [$renderer, $output] = $this->createRenderer();

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [], []),
        ]);

        $renderer->renderDecisionLogFromPlan($plan);

        $buffer = $output->fetch();

        self::assertStringNotContainsString('Decision Log', $buffer);
    }

    /**
     * Verifies that date drift detection works on ExecutionPlan entries.
     */
    #[Test]
    public function buildOutputEntriesFromPlanDetectsDateDrift(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/2024-01-15_10-00-00.jpg',
                    $baseDir . '/2024-06-20_10-00-00-000.jpg',
                    ExecutionItemType::Canonical,
                    renameRequired: true,
                    isNoOp: false,
                    groupKey: 'group-a',
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(maxDateDrift: 7),
            new RenameResult(),
            $baseDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Warning, $entries[0]->tag);
        self::assertTrue($entries[0]->shouldSkip);

        /** @var string $warningReason */
        $warningReason = $entries[0]->warningReason;
        self::assertStringContainsString('Date drift:', $warningReason);
    }

    /**
     * Verifies that ExecutionItem with type Skipped gets OutputEntryTag::Skipped.
     */
    #[Test]
    public function skippedItemTypeGetsSkippedTag(): void
    {
        [$renderer] = $this->createRenderer();

        $baseDir = '/tmp/source';

        $plan = new ExecutionPlan([
            new ExecutionGroup('group-a', false, null, [
                new ExecutionItem(
                    $baseDir . '/skip.jpg',
                    $baseDir . '/skip.jpg',
                    ExecutionItemType::Skipped,
                    renameRequired: false,
                    isNoOp: true,
                    groupKey: 'group-a',
                ),
            ]),
        ]);

        $entries = $renderer->buildOutputEntriesFromPlan(
            $plan,
            new RenameOptions(),
            new RenameResult(),
            $baseDir,
        )->entries;

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Skipped, $entries[0]->tag);
    }

    /**
     * @return array{RenameOutputRenderer, BufferedOutput}
     */
    private function createRenderer(): array
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        return [OutputRendererFactory::create($io), $output];
    }
}
