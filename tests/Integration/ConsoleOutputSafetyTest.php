<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use Imagick;
use MagicSunday\Renamer\Application;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Exercises real CLI output and application exception handling with synthetic
 * hostile filenames. Child-process behavior spans the composition root, native
 * parser and presentation layers; byte assertions never print active payloads.
 */
#[CoversNothing]
final class ConsoleOutputSafetyTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Creates real tiny JPEG originals, a matched duplicate and an orphan, then
     * captures lower/dedup previews and detailed verification through the actual
     * CLI. Control bytes cannot execute terminal commands and filename tags stay
     * literal. All source bytes remain unchanged after the read-only operations.
     *
     * @return void
     */
    #[Test]
    public function realCliViewsRenderHostileNamesWithoutChangingMedia(): void
    {
        $workspace  = $this->createTempWorkspace('console-safety-');
        $filesystem = new Filesystem();
        $media      = $workspace . '/media';
        $basename   = "École-<fg=red>CAMERA\x1B[2J\r\n\u{009b}";
        $sources    = [
            $media . '/' . $basename . '.JPG',
            $media . '/' . $basename . '-duplicate-1.JPG',
            $media . "/Orphan-<info>FAKE\x1B[2J\r\n-duplicate-1.JPG",
        ];
        $image = new Imagick();

        try {
            $image->newImage(16, 16, 'white');
            $image->setImageFormat('jpeg');
            $bytes = $image->getImagesBlob();

            foreach ($sources as $source) {
                $filesystem->dumpFile($source, $bytes);
            }

            foreach ([['rename:lower', '--dry-run'], ['rename:dedup', '--dry-run'], ['rename:verify', '--detail']] as [$command, $option]) {
                $process = new Process([
                    PHP_BINARY,
                    dirname(__DIR__, 2) . '/src/Renamer.php',
                    $command,
                    $media,
                    $option,
                    '--no-ansi',
                ], null, ['CACHE_DIR' => $workspace . '/cache', 'FILE_LINK_ROOT' => '', 'FILE_LINK_BASE' => '', 'FILE_LINK_PROTOCOL' => '']);
                $process->setTimeout(20.0);
                $process->run();
                $output = $process->getOutput() . $process->getErrorOutput();
                self::assertSame(0, $process->getExitCode(), json_encode($output, JSON_THROW_ON_ERROR));
                self::assertFalse(str_contains($output, "\x1B[2J"));
                self::assertFalse(str_contains($output, "\u{009b}"));
                self::assertFalse(str_contains($output, "\r\n"));
                self::assertTrue(str_contains($output, '\\u001b[2J\\r\\n'), $command . ': ' . json_encode($output, JSON_THROW_ON_ERROR));
                self::assertTrue(str_contains($output, '<fg=red>CAMERA') || str_contains($output, '<fg=red>camera'), $command . ': ' . json_encode($output, JSON_THROW_ON_ERROR));
            }

            foreach ($sources as $source) {
                self::assertSame($bytes, $filesystem->readFile($source));
            }
        } finally {
            $image->clear();
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Throws a hostile chained exception outside a command's own catch blocks.
     * Symfony's application boundary must retain the original exit code and
     * show both failures as literal, control-free diagnostics in quiet and
     * verbose mode. Original Throwable fields and verbose trace identities survive. This closes the framework renderer path alongside normal reports.
     *
     * @return void
     */
    #[Test]
    public function uncaughtExceptionChainIsInertAndRetainsItsExitCode(): void
    {
        $workspace = $this->createTempWorkspace('console-exception-');

        try {
            $failure         = new RuntimeException("<info>FAKE\x1B[2J\r\n", 42, new RuntimeException("previous\u{009b}\n"));
            $originalMessage = json_encode($failure->getMessage(), JSON_THROW_ON_ERROR);
            $originalFile    = $failure->getFile();
            $originalLine    = $failure->getLine();
            $command         = new Command('inspect:throw');
            $command->setCode(static fn (): never => throw $failure);
            $application = new Application([$command], new BatchRunLock(new Filesystem(), $workspace), RuntimeExecutorFactory::createSourceIdentityGuard(), RuntimeExecutorFactory::createMutationJournal($workspace));
            $application->setAutoExit(false);

            foreach ([false, true] as $verbose) {
                $output = new BufferedOutput();
                $input  = new ArrayInput(['command' => 'inspect:throw', $verbose ? '--verbose' : '--quiet' => true]);
                self::assertSame(42, $application->run($input, $output));
                $captured = $output->fetch();
                self::assertTrue(str_contains($captured, '<info>FAKE\\u001b[2J\\r\\n'));
                self::assertTrue(str_contains($captured, 'previous\\u009b\\n'));
                self::assertFalse(str_contains($captured, "\x1B[2J"));
                self::assertFalse(str_contains($captured, "\u{009b}"));
                self::assertSame($originalMessage, json_encode($failure->getMessage(), JSON_THROW_ON_ERROR));
                self::assertSame($originalFile, $failure->getFile());
                self::assertSame($originalLine, $failure->getLine());

                if ($verbose) {
                    self::assertTrue(str_contains($captured, 'In ' . $originalFile . ' line ' . $originalLine . ':'));
                    self::assertTrue(str_contains($captured, '#0 '));
                    self::assertTrue(str_contains($captured, 'uncaughtExceptionChainIsInertAndRetainsItsExitCode()'));
                }
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }
}
