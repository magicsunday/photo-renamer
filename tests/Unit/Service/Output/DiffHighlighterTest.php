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
use MagicSunday\Renamer\Service\Output\DiffHighlighter;
use MagicSunday\Renamer\Service\Output\DiffTokenState;
use MagicSunday\Renamer\Service\Output\PathPrefixSplit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Checks literal visible filenames after the real Console formatter consumes
 * diff styles. JSON assertions never emit active attack bytes on failure.
 */
#[CoversClass(DiffHighlighter::class)]
#[UsesClass(ConsoleText::class)]
#[UsesClass(DiffTokenState::class)]
#[UsesClass(PathPrefixSplit::class)]
final class DiffHighlighterTest extends TestCase
{
    /**
     * Exercises identical-path, same-directory filename and changed-directory
     * branches. Markup split across diff tokens must still remain literal, and
     * controls must become visible without becoming artificial path separators.
     *
     * @return void
     */
    #[Test]
    public function allDiffBranchesPreserveLiteralTagsAndNeutralizeControls(): void
    {
        $target      = "école/<fg=red>VID\x1B[2J\r\n\u{009b}.MOV";
        $expected    = 'école/<fg=red>VID\\u001b[2J\\r\\n\\u009b.MOV';
        $highlighter = new DiffHighlighter();
        $formatter   = new OutputFormatter(false);

        foreach ([$target, 'école/old.mov', 'autre/old.mov'] as $source) {
            $rendered = $formatter->format($highlighter->highlightDiff($source, $target, 'green'));
            self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($rendered, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Invalid Linux filename bytes must not make Unicode tokenization discard
     * the target. The safe byte display remains visible after diff formatting.
     *
     * @return void
     */
    #[Test]
    public function invalidUtf8TargetIsVisibleInChangedNameDiff(): void
    {
        $target   = "synthetic/broken-\xFF\x1B.JPG";
        $rendered = new OutputFormatter(false)->format(new DiffHighlighter()->highlightDiff('synthetic/source.jpg', $target, 'green'));
        self::assertSame(json_encode('synthetic/broken-\\xFF\\x1B.JPG', JSON_THROW_ON_ERROR), json_encode($rendered, JSON_THROW_ON_ERROR));
    }
}
