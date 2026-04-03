<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Output;

use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Service\Output\DiffHighlighter;
use MagicSunday\Renamer\Service\Output\OutputCounters;
use MagicSunday\Renamer\Service\Output\OutputEntryPresenter;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecider;
use MagicSunday\Renamer\Service\Output\SkipReasonFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the collaborator responsible for entry-line presentation inside the output module.
 *
 * The presenter now owns concrete CLI line layout, filter visibility, and
 * render counters for already projected `OutputEntry` objects. These tests keep
 * that presentation contract stable while `RenameOutputRenderer` becomes a
 * thinner visible boundary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(OutputEntryPresenter::class)]
#[CoversClass(OutputCounters::class)]
final class OutputEntryPresenterTest extends TestCase
{
    /**
     * Verifies that pure info entries stay hidden when the active filter excludes
     * their visual tag.
     *
     * This keeps duplicate-only or warning-only output free from orphaned info
     * continuation lines that belong to other presentation categories.
     */
    #[Test]
    public function renderHidesInfoEntriesWhenShowFilterExcludesThem(): void
    {
        [$presenter, $io, $output] = $this->createPresenter();

        $presenter->render(
            [
                OutputEntry::info(
                    sortKey: '/tmp/source/2019/companion.mov',
                    sourcePath: '2019/companion.mov',
                    reason: 'Live Photo pair across directories: <fg=cyan>2019/canonical.jpg</>',
                ),
            ],
            $io,
            '/tmp/source',
            ['D'],
        );

        self::assertSame('', $output->fetch());
    }

    /**
     * Verifies that a visible info entry renders as a standalone two-line block
     * when no visible anchor line was emitted for the same sort key.
     *
     * This preserves the usability of filtered `--show=I` output where the info
     * notice itself must provide both the anchor path and the explanation.
     */
    #[Test]
    public function renderShowsTwoLineInfoBlockWithoutVisibleAnchor(): void
    {
        [$presenter, $io, $output] = $this->createPresenter();

        $presenter->render(
            [
                OutputEntry::info(
                    sortKey: '/tmp/source/2019/companion.mov',
                    sourcePath: '2019/companion.mov',
                    reason: 'Live Photo pair across directories: <fg=cyan>2019/canonical.jpg</>',
                ),
            ],
            $io,
            '/tmp/source',
            ['I'],
        );

        $buffer = $output->fetch();

        self::assertStringContainsString('[I]', $buffer);
        self::assertStringContainsString('2019/companion.mov', $buffer);
        self::assertStringContainsString("\n      → ", $buffer);
        self::assertStringContainsString('Live Photo pair across directories: 2019/canonical.jpg', $buffer);
    }

    /**
     * Verifies that skipped warning renames use the two-line layout and still
     * contribute the correct render counters.
     *
     * The presenter must count duplicates and planned skips even when the entry
     * is rendered as a warning block instead of a one-line rename row.
     */
    #[Test]
    public function renderShowsTwoLineWarningBlockAndReturnsCounters(): void
    {
        [$presenter, $io, $output] = $this->createPresenter();

        $counters = $presenter->render(
            [
                OutputEntry::rename(
                    sortKey: '/tmp/source/2019/clip.mov',
                    sourcePath: '2019/clip.mov',
                    targetPath: '2019/clip.mov',
                    tag: OutputEntryTag::Warning,
                    isDuplicateTarget: true,
                    shouldSkip: true,
                    warningReason: 'Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone',
                ),
            ],
            $io,
            '/tmp/source',
            ['W'],
        );

        $buffer = $output->fetch();

        self::assertStringContainsString('[W]', $buffer);
        self::assertStringContainsString('2019/clip.mov', $buffer);
        self::assertStringContainsString("\n      → ", $buffer);
        self::assertStringContainsString('Ambiguous timezone: QuickTime UTC without offset', $buffer);
        self::assertSame(0, $counters->fileCount);
        self::assertSame(1, $counters->duplicateCount);
        self::assertSame(0, $counters->plannedMoves);
        self::assertSame(1, $counters->plannedSkips);
    }

    /**
     * Creates the presenter with real output collaborators and a buffered console.
     *
     * @return array{OutputEntryPresenter, SymfonyStyle, BufferedOutput} Presenter, console IO, and captured buffer
     */
    private function createPresenter(): array
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $presenter = new OutputEntryPresenter(
            new OutputSkipReasonDecider(),
            new SkipReasonFormatter(),
            new DiffHighlighter(),
        );

        return [$presenter, $io, $output];
    }
}
