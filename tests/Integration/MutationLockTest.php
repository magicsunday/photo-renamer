<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use FilesystemIterator;
use MagicSunday\Renamer\Application;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

use function is_file;
use function str_contains;

use const PHP_BINARY;

/**
 * Exercises the real application boundary: a held mutation lock must prevent
 * even analysis from starting, rather than only serialize individual moves.
 */
#[CoversNothing]
final class MutationLockTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Linux SIGKILL number; the Docker runtime has POSIX but does not require the
     * pcntl extension, which would otherwise define the named signal constant.
     */
    private const int KILL_SIGNAL = 9;

    /**
     * A second application using the same state directory must refuse a
     * mutating command while a cooperating run holds the exclusive resource.
     *
     * @param string $commandName Mutating command whose body must remain unexecuted
     *
     * @return void
     */
    #[Test]
    #[DataProvider('mutatingCommands')]
    public function heldLockPreventsCommandExecution(string $commandName): void
    {
        $workspace   = $this->createTempWorkspace();
        $lockService = new BatchRunLock(new Filesystem(), $workspace);
        $lock        = $lockService->acquire();

        try {
            $executed = false;
            $command  = new Command($commandName);
            $command->addArgument('source', InputArgument::REQUIRED);
            $command->addOption('dry-run', 'd', InputOption::VALUE_NONE);
            $command->setCode(static function () use (&$executed): int {
                $executed = true;

                return Command::SUCCESS;
            });
            $application = new Application([$command], $lockService, FileSystemServiceFactory::createSourceIdentityGuard());
            $application->setAutoExit(false);
            $output = new BufferedOutput();

            self::assertSame(Command::FAILURE, $application->run(new ArrayInput(['command' => $commandName, 'source' => $workspace . '/child']), $output));
            self::assertFalse($executed);
            self::assertStringContainsString('Another mutating renamer', $output->fetch());
        } finally {
            $lock->release();
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Every media-writing command shares the same resource, including commands
     * that bypass the normal rename executor. A future rename command also locks
     * by default instead of relying on an easy-to-forget registration list.
     *
     * @return iterable<string, array{string}> Canonical mutating command names
     */
    public static function mutatingCommands(): iterable
    {
        foreach (['exif', 'hash', 'pattern', 'date', 'lower', 'dedup', 'write-date', 'future'] as $name) {
            yield $name => ['rename:' . $name];
        }
    }

    /**
     * Parsed long/short dry-run flags and the verification command can run while
     * a mutation is locked. An ArrayInput false option must still take the lock;
     * inspecting only raw option presence would silently bypass protection.
     *
     * @param string              $commandName  Canonical command whose lock policy is exercised
     * @param array<string, bool> $options      Input options, including false dry-run
     * @param int                 $expectedCode Expected status while another batch owns the lock
     *
     * @return void
     */
    #[Test]
    #[DataProvider('readOnlyInputs')]
    public function parsedReadOnlyInputsRespectMutationPolicy(string $commandName, array $options, int $expectedCode): void
    {
        $workspace   = $this->createTempWorkspace();
        $lockService = new BatchRunLock(new Filesystem(), $workspace);
        $lock        = $lockService->acquire();

        try {
            $command = new Command($commandName);
            $command->addArgument('source', InputArgument::REQUIRED);
            $command->addOption('dry-run', 'd', InputOption::VALUE_NONE);
            $command->setCode(static fn (): int => Command::SUCCESS);
            $application = new Application([$command], $lockService, FileSystemServiceFactory::createSourceIdentityGuard());
            $application->setAutoExit(false);

            self::assertSame($expectedCode, $application->run(new ArrayInput(['command' => $commandName, 'source' => $workspace, ...$options]), new BufferedOutput()));
        } finally {
            $lock->release();
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Keeps all forms of read-only invocation distinct from a supplied false
     * flag, which programmatic Console callers may legitimately use.
     *
     * @return iterable<string, array{string, array<string, bool>, int}> Parsed policy cases
     */
    public static function readOnlyInputs(): iterable
    {
        yield 'long dry run' => ['rename:lower', ['--dry-run' => true], Command::SUCCESS];
        yield 'short dry run' => ['rename:dedup', ['-d' => true], Command::SUCCESS];
        yield 'verification' => ['rename:verify', [], Command::SUCCESS];
        yield 'false dry run' => ['rename:write-date', ['--dry-run' => false], Command::FAILURE];
    }

    /**
     * While the first application executes, a nested second application cannot
     * even enter its command body. Throwing from the first body must release the
     * lock so the next invocation succeeds without deleting persistent files.
     *
     * @return void
     */
    #[Test]
    public function lockCoversCommandBodyAndReleasesAfterException(): void
    {
        $workspace   = $this->createTempWorkspace();
        $lockService = new BatchRunLock(new Filesystem(), $workspace);

        try {
            $secondCommand = new Command('rename:dedup');
            $secondCommand->setCode(static fn (): int => Command::SUCCESS);
            $secondApplication = new Application([$secondCommand], $lockService, FileSystemServiceFactory::createSourceIdentityGuard());
            $secondApplication->setAutoExit(false);
            $command = new Command('rename:exif');
            $command->setCode(static function () use ($secondApplication): int {
                self::assertSame(Command::FAILURE, $secondApplication->run(new ArrayInput(['command' => 'rename:dedup']), new BufferedOutput()));

                throw new RuntimeException('Synthetic analysis failure.');
            });
            $application = new Application([$command], $lockService, FileSystemServiceFactory::createSourceIdentityGuard());
            $application->setAutoExit(false);
            $output = new BufferedOutput();

            self::assertSame(Command::FAILURE, $application->run(new ArrayInput(['command' => 'rename:exif']), $output));
            self::assertStringContainsString('Synthetic analysis failure.', $output->fetch());
            self::assertSame(Command::SUCCESS, $secondApplication->run(new ArrayInput(['command' => 'rename:dedup']), new BufferedOutput()));
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A child process explicitly announces acquisition before the contender runs.
     * SIGKILL then proves the kernel releases the lock even when finally cannot
     * run. The persistent lock inode remains and is reused; no timing sleeps or
     * stale-file deletion are necessary to recover.
     *
     * @return void
     */
    #[Test]
    public function processDeathReleasesLockWithoutDeletingItsFile(): void
    {
        $workspace = $this->createTempWorkspace();
        $input     = new InputStream();
        $process   = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/MutationLockWorker.php'], env: ['RENAMER_LOCK_TEST_DIRECTORY' => $workspace]);
        $process->setInput($input);
        $process->setTimeout(10.0);

        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => ($type === Process::OUT) && str_contains($output, "LOCKED\n")));

            $command = new Command('rename:lower');
            $command->setCode(static fn (): int => Command::SUCCESS);
            $application = new Application([$command], new BatchRunLock(new Filesystem(), $workspace), FileSystemServiceFactory::createSourceIdentityGuard());
            $application->setAutoExit(false);
            self::assertSame(Command::FAILURE, $application->run(new ArrayInput(['command' => 'rename:lower']), new BufferedOutput()));

            $lockFiles = new FilesystemIterator($workspace . '/locks', FilesystemIterator::SKIP_DOTS);
            $lockFile  = $lockFiles->current();
            self::assertInstanceOf(SplFileInfo::class, $lockFile);
            $lockPath = $lockFile->getPathname();
            $inode    = $lockFile->getInode();
            $process->signal(self::KILL_SIGNAL);
            $process->wait();

            self::assertFalse($process->isRunning());
            self::assertTrue(is_file($lockPath));
            self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'rename:lower']), new BufferedOutput()));
            self::assertSame($inode, new SplFileInfo($lockPath)->getInode());
        } finally {
            $input->close();
            $process->stop(0.0, self::KILL_SIGNAL);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A pre-existing lock-directory symlink must fail before any lock is created
     * or permissions are changed at its destination. State is private and shared
     * by cooperating runs, so a redirect must never select another lock resource.
     *
     * @return void
     */
    #[Test]
    public function redirectedLockDirectoryIsRejectedWithoutTouchingDestination(): void
    {
        $workspace   = $this->createTempWorkspace();
        $filesystem  = new Filesystem();
        $destination = $workspace . '/other';
        $filesystem->mkdir($destination, 0o755);
        $filesystem->symlink($destination, $workspace . '/locks');

        $mode = new SplFileInfo($destination)->getPerms();

        try {
            $command = new Command('rename:lower');
            $command->setCode(static fn (): int => Command::SUCCESS);
            $application = new Application([$command], new BatchRunLock($filesystem, $workspace), FileSystemServiceFactory::createSourceIdentityGuard());
            $application->setAutoExit(false);
            $output = new BufferedOutput();

            self::assertSame(Command::FAILURE, $application->run(new ArrayInput(['command' => 'rename:lower']), $output));
            self::assertStringContainsString('must not be a symbolic link', $output->fetch());
            self::assertSame($mode, new SplFileInfo($destination)->getPerms());
            self::assertCount(0, new FilesystemIterator($destination, FilesystemIterator::SKIP_DOTS));
        } finally {
            $filesystem->remove($workspace . '/locks');
            $this->removeWorkspace($workspace);
        }
    }
}
