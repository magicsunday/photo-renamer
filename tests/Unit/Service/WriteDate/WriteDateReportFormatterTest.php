<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\WriteDate;

use DateTimeImmutable;
use MagicSunday\Renamer\Service\WriteDate\WriteDatePendingWrite;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonCatalog;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReportFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the formatter used for write-date operator output.
 *
 * The formatter must preserve the grouped summary wording and the two-line
 * per-entry layout so refactors do not silently change the CLI that users rely
 * on for dry runs and metadata write reviews.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(WriteDateReportFormatter::class)]
#[UsesClass(WriteDatePendingWrite::class)]
#[UsesClass(WriteDateReasonCatalog::class)]
final class WriteDateReportFormatterTest extends TestCase
{
    /**
     * Verifies that the pending-write summary groups entries by reason and keeps
     * the existing "Found N file(s) needing metadata repair" wording.
     */
    #[Test]
    public function formatOverviewLinesGroupsPendingWritesByReason(): void
    {
        $formatter = new WriteDateReportFormatter();

        $lines = $formatter->formatOverviewLines(3, [
            new WriteDatePendingWrite('/tmp/a.jpg', WriteDateReasonCatalog::NODATA, 'no date in metadata', false, new DateTimeImmutable('2024-01-15 00:00:00'), false),
            new WriteDatePendingWrite('/tmp/b.jpg', WriteDateReasonCatalog::NODATA, 'no date in metadata', false, new DateTimeImmutable('2024-01-16 00:00:00'), false),
            new WriteDatePendingWrite('/tmp/c.mov', WriteDateReasonCatalog::TIMEZONE, 'QuickTime timestamp without timezone info', true, new DateTimeImmutable('2024-01-17 10:30:00'), true),
        ]);

        self::assertSame('<fg=cyan>Found 3 file(s) needing metadata repair:</>', $lines[0]);
        self::assertStringContainsString('2 files', $lines[1]);
        self::assertStringContainsString('no date in metadata', $lines[1]);
        self::assertStringContainsString('1 file', $lines[2]);
        self::assertStringContainsString('QuickTime timestamp without timezone info', $lines[2]);
    }

    /**
     * Verifies that the overview emits the historical green no-op message when
     * files were scanned but no metadata repair remains necessary.
     */
    #[Test]
    public function formatOverviewLinesBuildsNothingToDoNotice(): void
    {
        $formatter = new WriteDateReportFormatter();

        $lines = $formatter->formatOverviewLines(4, []);

        self::assertSame(['<fg=green>All files have correct metadata — nothing to do.</>'], $lines);
    }

    /**
     * Verifies that entry formatting keeps the aligned first line and appends a
     * second gray reason line when a classified reason is available.
     */
    #[Test]
    public function formatEntryBuildsTwoLineOutputForReasonedWrite(): void
    {
        $formatter = new WriteDateReportFormatter();

        $lines = $formatter->formatEntry(
            '<fg=yellow>[W]</>',
            '2024-01-15.jpg',
            '   ',
            'DateTimeOriginal: 2024:01:15 00:00:00',
            WriteDateReasonCatalog::NODATA,
            'no date in metadata',
        );

        self::assertSame(' <fg=yellow>[W]</> 2024-01-15.jpg    <fg=cyan>→</> DateTimeOriginal: 2024:01:15 00:00:00', $lines[0]);
        self::assertSame('      <fg=gray>[nodata] no date in metadata</>', $lines[1]);
    }

    /**
     * Verifies that failure entries remain single-line when no reason key is
     * available and only the failure detail should be shown.
     */
    #[Test]
    public function formatEntryKeepsFailureOutputSingleLine(): void
    {
        $formatter = new WriteDateReportFormatter();

        $lines = $formatter->formatEntry(
            '<fg=red>[E]</>',
            '2024-01-15.jpg',
            '',
            'FAILED to write: 2024:01:15 00:00:00',
        );

        self::assertCount(1, $lines);
        self::assertSame(' <fg=red>[E]</> 2024-01-15.jpg <fg=cyan>→</> FAILED to write: 2024:01:15 00:00:00', $lines[0]);
    }
}
