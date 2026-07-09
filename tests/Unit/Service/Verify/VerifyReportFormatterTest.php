<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Verify;

use MagicSunday\Renamer\Service\Output\SummaryRow;
use MagicSunday\Renamer\Service\Verify\VerifyCategoryCatalog;
use MagicSunday\Renamer\Service\Verify\VerifyCategorySection;
use MagicSunday\Renamer\Service\Verify\VerifyReportFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the formatter responsible for verify report sections and summaries.
 *
 * The formatter must preserve category ordering, filtering, stable sorting, and
 * the summary row structure so VerifyCommand refactors do not silently change
 * the operator-facing report layout.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(VerifyReportFormatter::class)]
#[UsesClass(SummaryRow::class)]
#[UsesClass(VerifyCategoryCatalog::class)]
#[UsesClass(VerifyCategorySection::class)]
final class VerifyReportFormatterTest extends TestCase
{
    /**
     * Verifies that the post-scan overview starts with the grouped issue header
     * and then appends only non-empty categories in stable catalog order.
     */
    #[Test]
    public function formatOverviewLinesBuildsGroupedIssueSummary(): void
    {
        $formatter = new VerifyReportFormatter();

        $lines = $formatter->formatOverviewLines(5, [
            VerifyCategoryCatalog::TIMEZONE  => ['clip.mov'],
            VerifyCategoryCatalog::FALLBACK  => [],
            VerifyCategoryCatalog::DRIFT     => [],
            VerifyCategoryCatalog::LIVEPHOTO => ['IMG_0001.mov'],
            VerifyCategoryCatalog::ERROR     => [],
            VerifyCategoryCatalog::NODATA    => [],
            VerifyCategoryCatalog::FILETYPE  => [],
        ]);

        self::assertSame('<fg=cyan>Found 2 issue(s) in 5 scanned file(s):</>', $lines[0]);
        self::assertSame('  1 Ambiguous timezone', $lines[1]);
        self::assertSame('  1 Missing Live Photo companion', $lines[2]);
        self::assertCount(3, $lines);
    }

    /**
     * Verifies that the overview emits the historical green no-issues notice
     * when files were scanned but no findings remain.
     */
    #[Test]
    public function formatOverviewLinesBuildsNoIssuesNotice(): void
    {
        $formatter = new VerifyReportFormatter();

        $lines = $formatter->formatOverviewLines(3, [
            VerifyCategoryCatalog::TIMEZONE  => [],
            VerifyCategoryCatalog::FALLBACK  => [],
            VerifyCategoryCatalog::DRIFT     => [],
            VerifyCategoryCatalog::LIVEPHOTO => [],
            VerifyCategoryCatalog::ERROR     => [],
            VerifyCategoryCatalog::NODATA    => [],
            VerifyCategoryCatalog::FILETYPE  => [],
        ]);

        self::assertSame(['<fg=green>All files OK — no metadata issues found.</>'], $lines);
    }

    /**
     * Verifies that category sections respect the show filter, stay label-based,
     * and sort filenames for deterministic output.
     */
    #[Test]
    public function formatCategorySectionsFiltersAndSortsEntries(): void
    {
        $formatter = new VerifyReportFormatter();

        $sections = $formatter->formatCategorySections([
            VerifyCategoryCatalog::TIMEZONE  => ['b.mov', 'a.mov'],
            VerifyCategoryCatalog::FALLBACK  => [],
            VerifyCategoryCatalog::DRIFT     => [],
            VerifyCategoryCatalog::LIVEPHOTO => [],
            VerifyCategoryCatalog::ERROR     => [],
            VerifyCategoryCatalog::NODATA    => [],
            VerifyCategoryCatalog::FILETYPE  => ['readme.txt'],
        ], [VerifyCategoryCatalog::TIMEZONE]);

        self::assertCount(1, $sections);
        self::assertSame('Ambiguous timezone', $sections[0]->label);
        self::assertSame(['a.mov', 'b.mov'], $sections[0]->files);
        self::assertFalse($sections[0]->detail);
    }

    /**
     * Verifies that multi-line detail entries are marked as detail sections so
     * the command can keep the extra blank line between expanded entries.
     */
    #[Test]
    public function formatCategorySectionsDetectsDetailEntries(): void
    {
        $formatter = new VerifyReportFormatter();

        $sections = $formatter->formatCategorySections([
            VerifyCategoryCatalog::TIMEZONE  => ["clip.mov\nproblem line"],
            VerifyCategoryCatalog::FALLBACK  => [],
            VerifyCategoryCatalog::DRIFT     => [],
            VerifyCategoryCatalog::LIVEPHOTO => [],
            VerifyCategoryCatalog::ERROR     => [],
            VerifyCategoryCatalog::NODATA    => [],
            VerifyCategoryCatalog::FILETYPE  => [],
        ], null);

        self::assertTrue($sections[0]->detail);
    }

    /**
     * Verifies that summary rows start with scanned/OK counts and then append
     * only non-empty categories in the catalog's stable order.
     */
    #[Test]
    public function formatSummaryRowsIncludesOnlyNonEmptyCategories(): void
    {
        $formatter = new VerifyReportFormatter();

        $rows = $formatter->formatSummaryRows(5, 3, [
            VerifyCategoryCatalog::TIMEZONE  => ['clip.mov'],
            VerifyCategoryCatalog::FALLBACK  => [],
            VerifyCategoryCatalog::DRIFT     => [],
            VerifyCategoryCatalog::LIVEPHOTO => ['IMG_0001.mov'],
            VerifyCategoryCatalog::ERROR     => [],
            VerifyCategoryCatalog::NODATA    => [],
            VerifyCategoryCatalog::FILETYPE  => [],
        ]);

        self::assertSame('Scanned files', $rows[0]->label);
        self::assertSame('5', $rows[0]->value);
        self::assertSame('OK', $rows[1]->label);
        self::assertSame('3', $rows[1]->value);
        self::assertSame('Ambiguous timezone', $rows[2]->label);
        self::assertSame('1', $rows[2]->value);
        self::assertSame('Missing Live Photo companion', $rows[3]->label);
        self::assertSame('1', $rows[3]->value);
        self::assertCount(4, $rows);
    }
}
