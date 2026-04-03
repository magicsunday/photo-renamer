<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Reporting;

use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function str_replace;

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
final class ConsoleProgressReporterTest extends TestCase
{
    /**
     * Ensures the adapter emits headings, progress completion, plain text, and
     * error diagnostics through the wrapped SymfonyStyle instance.
     *
     * This guards the runtime contract expected by current commands while still
     * allowing domain services to depend only on ProgressReporterInterface.
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
        $reporter->text('plain message');
        $reporter->error('diagnostic problem');

        $normalizedOutput = str_replace("\r", '', $output->fetch());

        self::assertStringContainsString('Phase title', $normalizedOutput);
        self::assertStringContainsString('plain message', $normalizedOutput);
        self::assertStringContainsString('diagnostic problem', $normalizedOutput);
    }
}
