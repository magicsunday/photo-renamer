<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Output;

use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\OutputEntryType;
use MagicSunday\Renamer\Service\Output\DiffHighlighter;
use MagicSunday\Renamer\Service\Output\OutputCounters;
use MagicSunday\Renamer\Service\Output\OutputEntryPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function str_contains;
use function substr_count;

/**
 * Verifies the collaborator responsible for entry-line presentation inside the output module.
 *
 * The presenter now owns concrete CLI line layout, filter visibility, and
 * render counters for already projected `OutputEntry` objects. These tests keep
 * final skip text, console escaping and layout stable at the presentation boundary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(OutputEntryPresenter::class)]
#[CoversClass(OutputCounters::class)]
#[UsesClass(ConsoleText::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(PathHelper::class)]
#[UsesClass(LinkConfig::class)]
#[UsesClass(OutputEntry::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(OutputEntryType::class)]
final class OutputEntryPresenterTest extends TestCase
{
    /**
     * Explicit link settings can differ between renders on one presenter,
     * without consulting or modifying environment. A later configuration must
     * not leak its destination into a prior configuration's repeated output.
     *
     * @return void
     */
    #[Test]
    public function explicitLinkConfigurationsRemainIndependentAcrossRenders(): void
    {
        [$presenter, $io, $output] = $this->createPresenter();
        $output->setDecorated(true);
        $entries = [OutputEntry::info('/synthetic/capture.jpg', '/synthetic/capture.jpg', 'synthetic notice')];
        $first   = new LinkConfig('/synthetic', '/host-first', null);
        $second  = new LinkConfig('/synthetic', '/host-second', null);
        $presenter->render($entries, $io, '/synthetic', null, $first);
        $firstText = $output->fetch();
        $presenter->render($entries, $io, '/synthetic', null, $second);
        $secondText = $output->fetch();
        $presenter->render($entries, $io, '/synthetic', null, $first);
        $repeatedText = $output->fetch();
        self::assertTrue(str_contains($firstText, '/host-first'));
        self::assertTrue(str_contains($secondText, '/host-second'));
        self::assertFalse(str_contains($secondText, '/host-first'));
        self::assertTrue($firstText === $repeatedText);
    }

    /**
     * Candidate source and counterpart links share the host mapping while hostile
     * path bytes stay inert. Filtering hides the entire diagnostic without changing
     * planned skips; disabled links retain the same readable relative paths.
     */
    #[Test]
    public function candidateCounterpartUsesSafeRelativeLinksAndRespectsFilters(): void
    {
        [$presenter, $io, $output] = $this->createPresenter();
        $output->setDecorated(true);
        $entries = [OutputEntry::rename(
            sortKey: '/synthetic/Trips/école.jpg',
            sourcePath: 'Trips/école.jpg',
            targetPath: 'Trips/renamed.jpg',
            tag: OutputEntryTag::Candidate,
            shouldSkip: true,
            counterpartPath: "/synthetic/Trips/<fg=red>école\n\x1B[2J.mov",
        )];
        $config = new LinkConfig('/synthetic', 'F:\\Photos', 'photo-select');

        $visible = $presenter->render($entries, $io, '/synthetic', ['C'], $config);
        $buffer  = $output->fetch();
        self::assertTrue(str_contains($buffer, 'photo-select:///F:/Photos/Trips/%C3%A9cole.jpg'));
        self::assertTrue(str_contains($buffer, 'photo-select:///F:/Photos/Trips/%3Cfg%3Dred%3E%C3%A9cole%0A%1B%5B2J.mov'));
        self::assertTrue(str_contains($buffer, 'Possible counterpart: '));
        self::assertTrue(str_contains($buffer, 'Trips/<fg=red>école\\n\\u001b[2J.mov'));
        self::assertFalse(str_contains($buffer, "\x1B[2J"));
        self::assertSame(4, substr_count($buffer, "\x1B]8;;"));
        self::assertSame(1, $visible->plannedSkips);
        self::assertSame(0, $visible->plannedMoves);

        $hidden = $presenter->render($entries, $io, '/synthetic', ['R'], $config);
        self::assertSame('', $output->fetch());
        self::assertSame($visible->plannedSkips, $hidden->plannedSkips);
        self::assertSame($visible->plannedMoves, $hidden->plannedMoves);

        $output->setDecorated(false);
        $presenter->render($entries, $io, '/synthetic', ['C']);
        $plain = $output->fetch();
        self::assertTrue(str_contains($plain, 'Possible counterpart: Trips/<fg=red>école\\n\\u001b[2J.mov'));
        self::assertFalse(str_contains($plain, 'photo-select:'));
    }

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
                    reason: 'Live Photo pair across directories: 2019/canonical.jpg',
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
                    reason: 'Live Photo pair across directories: 2019/canonical.jpg',
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
     * Captures both an initial reason block and its continuation line using
     * hostile filename/metadata strings. Tags must stay literal and CR/LF/ESC
     * must not add rows or counterfeit a success status. Boolean assertions
     * keep the captured payload inert even if presentation regresses.
     *
     * @return void
     */
    #[Test]
    public function reasonsAndContinuationLinesCannotInjectMarkupOrRows(): void
    {
        [$presenter, $io, $output] = $this->createPresenter();
        $path                      = "<fg=red>école\r\n\x1B[2J.jpg";
        $reason                    = "<info>fake success</info>\r\n\x1B[2J";
        $presenter->render([
            OutputEntry::info('synthetic', $path, $reason),
            OutputEntry::info('synthetic', $path, $reason),
        ], $io);
        $buffer = $output->fetch();
        self::assertTrue(str_contains($buffer, '<fg=red>école'));
        self::assertTrue(str_contains($buffer, '<info>fake success</info>'));
        self::assertTrue(str_contains($buffer, '\\r\\n\\u001b[2J'));
        self::assertFalse(str_contains($buffer, "\x1B[2J"));
        self::assertFalse(str_contains($buffer, "\r"));
        self::assertSame(3, substr_count($buffer, "\n"));
    }

    /**
     * Preserves the existing tag/conditional-detail matrix at the observable
     * two-line CLI boundary. Null keeps the default text, an empty message stays
     * empty, and unrelated detail fields cannot override the tag's meaning.
     *
     * @param OutputEntryTag $tag          Exclusive tag selecting the skipped rename text
     * @param string|null    $reason       Supplemental review detail
     * @param string|null    $warning      Supplemental warning detail
     * @param string         $expectedText Exact plain-text explanation on the second line
     *
     * @return void
     */
    #[Test]
    #[DataProvider('tagDecisions')]
    public function everyTagPreservesItsConditionalSkipText(
        OutputEntryTag $tag,
        ?string $reason,
        ?string $warning,
        string $expectedText,
    ): void {
        [$presenter, $io, $output] = $this->createPresenter();
        $entry                     = new OutputEntry(
            sortKey: '/synthetic/source',
            type: OutputEntryType::Rename,
            tag: $tag,
            sourcePath: '/synthetic/source',
            shouldSkip: true,
            reason: $reason,
            warningReason: $warning,
        );
        $presenter->render([$entry], $io);

        self::assertSame('  [' . $tag->letter() . '] /synthetic/source' . "\n      → " . $expectedText . "\n", $output->fetch());
    }

    /**
     * Provides every exclusive tag with independent expected output text and
     * null, empty or custom detail partitions.
     *
     * @return iterable<string, array{OutputEntryTag, string|null, string|null, string}> Tag, conditional details and final text
     */
    public static function tagDecisions(): iterable
    {
        yield 'candidate ignores other details' => [OutputEntryTag::Candidate, 'unrelated reason', 'unrelated warning', 'Possible Live Photo pair with different content IDs; skipped for manual review'];
        yield 'fallback ignores other details' => [OutputEntryTag::Fallback, 'unrelated reason', 'unrelated warning', 'Fallback date: DateTime (0x0132) used instead of DateTimeOriginal'];
        yield 'review null' => [OutputEntryTag::Review, null, 'unrelated warning', 'Cross-group video review required'];
        yield 'review empty' => [OutputEntryTag::Review, '', 'unrelated warning', ''];
        yield 'review custom' => [OutputEntryTag::Review, 'Synthetic video review', 'unrelated warning', 'Synthetic video review'];
        yield 'warning null' => [OutputEntryTag::Warning, 'unrelated reason', null, 'Warning'];
        yield 'warning empty' => [OutputEntryTag::Warning, 'unrelated reason', '', ''];
        yield 'warning timezone remains generic warning' => [OutputEntryTag::Warning, 'unrelated reason', 'Ambiguous timezone: custom warning', 'Ambiguous timezone: custom warning'];

        foreach ([OutputEntryTag::Rename, OutputEntryTag::Duplicate, OutputEntryTag::Original, OutputEntryTag::Skipped, OutputEntryTag::Error, OutputEntryTag::Info] as $tag) {
            yield $tag->value . ' generic null' => [$tag, null, null, 'Skipped'];
            yield $tag->value . ' generic ignores details' => [$tag, 'unrelated reason', 'unrelated warning', 'Skipped'];
        }
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
            new DiffHighlighter(),
        );

        return [$presenter, $io, $output];
    }
}
