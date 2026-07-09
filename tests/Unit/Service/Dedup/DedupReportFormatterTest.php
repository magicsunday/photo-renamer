<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Dedup;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Service\Dedup\DedupReportFormatter;
use MagicSunday\Renamer\Service\Output\SummaryRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the formatter used for dedup command output.
 *
 * The formatter must preserve the historical wording and two-line duplicate
 * action layout so command refactors do not silently alter the operator-facing
 * dry-run and execution output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DedupReportFormatter::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(SummaryRow::class)]
final class DedupReportFormatterTest extends TestCase
{
    /**
     * Verifies that the overview reports duplicate/actionable/orphan counts and
     * includes the action line when duplicates can actually be processed.
     */
    #[Test]
    public function formatOverviewLinesBuildsGroupedDuplicateSummary(): void
    {
        $formatter = new DedupReportFormatter();

        $lines = $formatter->formatOverviewLines(3, 2, 1, false, true);

        self::assertSame('<fg=cyan>Found 3 duplicate file(s) (2 actionable, 1 orphaned).</>', $lines[0]);
        self::assertSame('  Action: <fg=yellow>move</> duplicates whose original still exists.', $lines[1]);
        self::assertCount(2, $lines);
    }

    /**
     * Verifies that the formatter preserves the historical green no-op message
     * when no duplicate files were found in a non-empty scan.
     */
    #[Test]
    public function formatOverviewLinesBuildsNothingToDoNotice(): void
    {
        $formatter = new DedupReportFormatter();

        $lines = $formatter->formatOverviewLines(0, 0, 0, false, true);

        self::assertSame(['<fg=green>No duplicate files found — nothing to do.</>'], $lines);
    }

    /**
     * Verifies that duplicate actions stay in the historical two-line block
     * format with the arrow on the indented second line.
     */
    #[Test]
    public function formatIndentedActionBuildsTwoLineBlock(): void
    {
        $formatter = new DedupReportFormatter();

        $line = $formatter->formatIndentedAction('cyan', '2025/a-duplicate-001.jpg', 'Would delete');

        self::assertSame(
            '<fg=cyan>[D]</> 2025/a-duplicate-001.jpg' . "\n" . '     <fg=cyan>→</> Would delete',
            $line,
        );
    }

    /**
     * Verifies that the footer summary preserves the historical row order and
     * still appends orphaned files only when they are present.
     */
    #[Test]
    public function formatSummaryRowsPreservesFooterOrdering(): void
    {
        $formatter = new DedupReportFormatter();

        $rows = $formatter->formatSummaryRows(10, 3, 1, 2048);

        self::assertSame('Scanned files', $rows[0]->label);
        self::assertSame('10', $rows[0]->value);
        self::assertSame('Duplicates found', $rows[1]->label);
        self::assertSame('3', $rows[1]->value);
        self::assertSame('Orphaned (skipped)', $rows[2]->label);
        self::assertSame('1', $rows[2]->value);
        self::assertSame('Space reclaimable', $rows[3]->label);
        self::assertCount(4, $rows);
    }
}
