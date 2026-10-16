<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Reporting;

use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function str_contains;
use function str_replace;
use function substr_count;

/**
 * Verifies that ConsoleProgressReporter adapts the narrow reporting contract to
 * the existing SymfonyStyle console behavior without leaking console concerns
 * back into domain services.
 *
 * The test deliberately checks only the operator-visible boundary:
 * section headings, informational text, progress completion, and diagnostics
 * should all reach the buffered console output through the adapter.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ConsoleProgressReporter::class)]
#[UsesClass(ConsoleText::class)]
final class ConsoleProgressReporterTest extends TestCase
{
    /**
     * Emits headings, consistently indented known/unknown-total progress, plain
     * text and error diagnostics through the wrapped SymfonyStyle instance.
     *
     * Unknown counters keep exactly one leading space at every tested width;
     * Symfony's default four-character padding must not add another indent.
     */
    #[Test]
    public function itEmitsConsoleVisibleMessagesAndProgress(): void
    {
        $output   = new BufferedOutput();
        $reporter = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $output));

        $reporter->section('Phase title');
        $reporter->startProgress(2);
        $reporter->advance();
        $reporter->advance();
        $reporter->finish();

        foreach ([0, 1, 42, 438, 2943, 71733] as $count) {
            $reporter->startProgress(0);
            $reporter->advance($count);
            $reporter->finish();
        }

        $reporter->text('plain message');
        $reporter->error('diagnostic problem');

        $normalizedOutput = str_replace("\r", '', $output->fetch());

        self::assertStringContainsString('Phase title', $normalizedOutput);
        self::assertStringContainsString(' 2/2 [', $normalizedOutput);

        foreach ([0, 1, 42, 438, 2943, 71733] as $count) {
            self::assertStringContainsString("\n " . $count . ' file(s) | Elapsed:', $normalizedOutput);
        }

        self::assertStringContainsString('plain message', $normalizedOutput);
        self::assertStringContainsString('diagnostic problem', $normalizedOutput);
    }

    /**
     * Metadata/parser diagnostics must retain literal tags and visible controls
     * in both normal errors and debug messages. Capture bytes as booleans so a
     * failing assertion cannot execute the hostile payload in the test console.
     *
     * @return void
     */
    #[Test]
    public function diagnosticsCannotInjectFormattingOrTerminalActions(): void
    {
        $output   = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        $reporter = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $output));
        $payload  = "<info>FORGED</info>\x1B[2J\r\n\u{009b}";
        $reporter->error($payload);
        $reporter->debug($payload);

        $captured = $output->fetch();
        self::assertSame(2, substr_count($captured, '<info>FORGED</info>\\u001b[2J\\r\\n\\u009b'));
        self::assertFalse(str_contains($captured, "\x1B"));
        self::assertFalse(str_contains($captured, "\r"));
        self::assertFalse(str_contains($captured, "\u{009b}"));
    }
}
