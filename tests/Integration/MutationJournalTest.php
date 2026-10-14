<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use MagicSunday\Renamer\Application;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

use function base64_encode;
use function clearstatcache;
use function dirname;
use function explode;
use function fileperms;
use function glob;
use function json_decode;
use function str_contains;
use function substr_count;
use function trim;
use function umask;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Uses real source mutations and an injected journal append failure to separate
 * failure before mutation from missing acknowledgement after a successful move.
 * Neither case may silently continue writing the remaining batch.
 */
#[CoversNothing]
final class MutationJournalTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Linux SIGKILL bypasses finally so this test proves OS-level lock recovery
     * and persistence of completed receipts rather than normal shutdown behavior.
     */
    private const int KILL_SIGNAL = 9;

    /**
     * The application must establish its initial journal record before entering
     * any mutating command body. Initialization failure preserves source bytes
     * and releases the lock so recovery does not require deleting its inode.
     *
     * @return void
     */
    #[Test]
    public function initializationFailurePreventsCommandEntryAndReleasesLock(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $source     = $workspace . '/media/source.jpg';
        $filesystem->dumpFile($source, 'synthetic source');
        $failingFilesystem = self::createPartialMock(Filesystem::class, ['appendToFile']);
        $failingFilesystem->expects(self::once())->method('appendToFile')->willThrowException(new IOException('Controlled initial journal failure.'));
        $lockService = new BatchRunLock($filesystem, $workspace . '/state');
        $entered     = false;
        $command     = new Command('rename:lower');
        $command->addArgument('source', InputArgument::REQUIRED);
        $command->setCode(static function () use (&$entered): int {
            $entered = true;

            return Command::SUCCESS;
        });
        $application = new Application([$command], $lockService, RuntimeExecutorFactory::createSourceIdentityGuard(), RuntimeExecutorFactory::createMutationJournal($workspace . '/state', $failingFilesystem));
        $application->setAutoExit(false);

        try {
            self::assertSame(Command::FAILURE, $application->run(new ArrayInput(['command' => 'rename:lower', 'source' => $workspace . '/media']), new BufferedOutput()));
            self::assertFalse($entered);
            self::assertSame('synthetic source', $filesystem->readFile($source));
            $lock = $lockService->acquire();
            self::assertTrue($lock->isAcquired());
            $lock->release();
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Real non-UTF-8 filename bytes must survive serialization, and a runtime
     * suffix must be recorded as the actual target rather than the planned
     * occupied path. Private permissions apply even with a permissive process
     * umask; the unrelated occupied target remains byte-for-byte intact.
     *
     * @return void
     */
    #[Test]
    public function receiptsPreserveFilenameBytesAndActualFallbackTargetPrivately(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $source     = $workspace . "/camera-\xff.jpg";
        $planned    = $workspace . '/capture.jpg';
        $filesystem->dumpFile($source, 'synthetic source');
        $filesystem->dumpFile($planned, 'existing target');

        $journal       = RuntimeExecutorFactory::createMutationJournal($workspace . '/state');
        $mover         = new RuntimeFileMoveExecutor(new NullProgressReporter(), $filesystem, new RuntimeCollisionPathAllocator(), RuntimeExecutorFactory::createSourceIdentityGuard(), $journal);
        $previousUmask = umask(0o022);

        try {
            $journal->begin('rename:lower');
            $path = $journal->pathname();
            self::assertNotNull($path);
            $occupied = [$source => true, $planned => true];
            $actual   = $mover->moveFileByPath($source, $planned, $occupied, false);
            self::assertSame($workspace . '/capture-duplicate-001.jpg', $actual);
            self::assertTrue($journal->finish(Command::SUCCESS));
            self::assertSame('existing target', $filesystem->readFile($planned));
            self::assertSame('synthetic source', $filesystem->readFile($actual));
            $lines = explode("\n", trim($filesystem->readFile($path)));
            self::assertCount(4, $lines);
            $intent  = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
            $receipt = json_decode($lines[2], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($intent);
            self::assertIsArray($receipt);
            self::assertSame('intent', $intent['event']);
            self::assertSame(base64_encode($source), $intent['source_b64']);
            self::assertSame(base64_encode($actual), $intent['target_b64']);
            self::assertSame('completed', $receipt['event']);
            self::assertSame($intent['id'], $receipt['id']);
            clearstatcache(true);
            self::assertSame(0o600, fileperms($path) & 0o777);
            self::assertSame(0o700, fileperms(dirname($path)) & 0o777);
        } finally {
            umask($previousUmask);
            $journal->finish(Command::FAILURE);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Kills a real CLI child after a confirmed first receipt. The old journal
     * identifies both components and remains byte-for-byte unchanged. A fresh
     * application observes current disk state, preserves the already moved still
     * and moves only its remaining companion into a separate completed journal.
     *
     * @return void
     */
    #[Test]
    public function interruptedPairRetainsEvidenceAndRequiresFreshRecoveryPlan(): void
    {
        $workspace       = $this->createTempWorkspace();
        $filesystem      = new Filesystem();
        $still           = $workspace . '/media/camera.jpg';
        $companion       = $workspace . '/media/camera.mov';
        $stillTarget     = $workspace . '/media/capture.jpg';
        $companionTarget = $workspace . '/media/capture.mov';
        $filesystem->dumpFile($still, 'synthetic still');
        $filesystem->dumpFile($companion, 'synthetic companion');

        $input   = new InputStream();
        $process = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/InterruptedMutationWorker.php'], env: ['RENAMER_JOURNAL_TEST_DIRECTORY' => $workspace]);
        $process->setInput($input);
        $process->setTimeout(10.0);

        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => ($type === Process::OUT) && str_contains($output, "FIRST_MOVE_RECORDED\n")));
            $process->signal(self::KILL_SIGNAL);
            $process->wait();
            self::assertFalse($process->isRunning());
            self::assertFileDoesNotExist($still);
            self::assertFileDoesNotExist($companionTarget);
            self::assertSame('synthetic still', $filesystem->readFile($stillTarget));
            self::assertSame('synthetic companion', $filesystem->readFile($companion));
            $journals = glob($workspace . '/state/private-*/mutation-*.jsonl');
            self::assertIsArray($journals);
            self::assertCount(1, $journals);
            $interrupted = $filesystem->readFile($journals[0]);
            self::assertSame(2, substr_count($interrupted, '"event":"pair_member"'));
            self::assertSame(1, substr_count($interrupted, '"event":"completed"'));
            self::assertStringNotContainsString('"event":"run_finished"', $interrupted);

            $guard    = RuntimeExecutorFactory::createSourceIdentityGuard();
            $journal  = RuntimeExecutorFactory::createMutationJournal($workspace . '/state');
            $reporter = new NullProgressReporter();
            $executor = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), $guard, $journal), $journal);
            $result   = null;
            $command  = new Command('rename:exif');
            $command->addArgument('source', InputArgument::REQUIRED);
            $command->setCode(static function () use ($executor, $stillTarget, $companion, $companionTarget, &$result): int {
                $result = $executor->executePlan(new ExecutionPlan([new ExecutionGroup('synthetic pair', true, $stillTarget, [
                    new ExecutionItem($stillTarget, $stillTarget, ExecutionItemType::Canonical, false, true, 'synthetic pair', isExecutable: false),
                    new ExecutionItem($companion, $companionTarget, ExecutionItemType::Companion, true, false, 'synthetic pair'),
                ])]));

                return $result->runtimeErrors === 0 ? Command::SUCCESS : Command::FAILURE;
            });
            $application = new Application([$command], new BatchRunLock($filesystem, $workspace . '/state'), $guard, $journal);
            $application->setAutoExit(false);
            $filesystem->dumpFile($companionTarget, 'foreign recovery target');
            self::assertSame(Command::FAILURE, $application->run(new ArrayInput(['command' => 'rename:exif', 'source' => $workspace . '/media']), new BufferedOutput()));
            self::assertNotNull($result);
            self::assertSame(0, $result->executedMoves);
            self::assertSame(1, $result->partialLivePhotoGroups);
            self::assertSame('foreign recovery target', $filesystem->readFile($companionTarget));
            self::assertSame('synthetic companion', $filesystem->readFile($companion));
            self::assertSame($interrupted, $filesystem->readFile($journals[0]));

            // Only explicit resolution of this synthetic foreign target allows
            // a fresh run; no receipt automatically overwrites or rolls back.
            $filesystem->remove($companionTarget);
            self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'rename:exif', 'source' => $workspace . '/media']), new BufferedOutput()));
            self::assertSame(1, $result->executedMoves);
            self::assertSame(0, $result->partialLivePhotoGroups);
            self::assertSame('synthetic still', $filesystem->readFile($stillTarget));
            self::assertSame('synthetic companion', $filesystem->readFile($companionTarget));
            self::assertFileDoesNotExist($companion);
            self::assertSame($interrupted, $filesystem->readFile($journals[0]));
            $afterRecovery = glob($workspace . '/state/private-*/mutation-*.jsonl');
            self::assertIsArray($afterRecovery);
            self::assertCount(3, $afterRecovery);
        } finally {
            $input->close();
            $process->stop(0.0, self::KILL_SIGNAL);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Controls the exact journal event that fails without sleeps. An intent
     * failure must preserve both files; a receipt failure must count the first
     * real move, retain the second source and return failure. The successful
     * control requires a persistent journal containing both actual targets.
     *
     * @param string|null $failedEvent Event whose append is rejected, or null for the successful control
     * @param int         $moves       Expected actual moves before the journal boundary stops the batch
     * @param int         $appends     Exact append boundary exercised by the controlled failure
     *
     * @return void
     */
    #[Test]
    #[DataProvider('appendFailures')]
    public function journalFailurePreservesActualMutationCounts(?string $failedEvent, int $moves, int $appends): void
    {
        $workspace      = $this->createTempWorkspace();
        $realFilesystem = new Filesystem();
        $filesystem     = $this->getMockBuilder(Filesystem::class)->onlyMethods(['appendToFile'])->getMock();
        $filesystem->expects(self::exactly($appends))->method('appendToFile')->willReturnCallback(static function (string $path, string $contents, bool $lock = false) use ($realFilesystem, $failedEvent): void {
            if (($failedEvent !== null) && str_contains($contents, '"event":"' . $failedEvent . '"')) {
                throw new IOException('Controlled journal append failure.');
            }

            $realFilesystem->appendToFile($path, $contents, $lock);
        });
        $first        = $workspace . '/media/first.jpg';
        $second       = $workspace . '/media/second.mov';
        $firstTarget  = $workspace . '/media/renamed.jpg';
        $secondTarget = $workspace . '/media/renamed.mov';
        $realFilesystem->dumpFile($first, 'synthetic still');
        $realFilesystem->dumpFile($second, 'synthetic companion');

        $guard    = RuntimeExecutorFactory::createSourceIdentityGuard();
        $journal  = RuntimeExecutorFactory::createMutationJournal($workspace . '/state', $filesystem);
        $reporter = new NullProgressReporter();
        $executor = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), $guard, $journal), $journal);
        $result   = null;
        $command  = new Command('rename:exif');
        $command->addArgument('source', InputArgument::REQUIRED);
        $command->addOption('dry-run', 'd', InputOption::VALUE_NONE);
        $command->setCode(static function (InputInterface $input) use ($executor, $first, $second, $firstTarget, $secondTarget, &$result): int {
            $result = $executor->executePlan(new ExecutionPlan([
                new ExecutionGroup('synthetic pair', true, $first, [
                    new ExecutionItem($first, $firstTarget, ExecutionItemType::Canonical, true, false, 'synthetic pair'),
                    new ExecutionItem($second, $secondTarget, ExecutionItemType::Companion, true, false, 'synthetic pair'),
                ]),
            ]), $input->getOption('dry-run') === true);

            return $result->runtimeErrors === 0 ? Command::SUCCESS : Command::FAILURE;
        });
        $application = new Application([$command], new BatchRunLock($filesystem, $workspace . '/state'), $guard, $journal);
        $application->setAutoExit(false);

        try {
            $status = $application->run(new ArrayInput(['command' => 'rename:exif', 'source' => $workspace . '/media']), new BufferedOutput());
            self::assertNotNull($result);
            self::assertSame($moves, $result->executedMoves);
            self::assertSame($moves === 1 ? 1 : 0, $result->partialLivePhotoGroups);
            self::assertSame($failedEvent === null ? Command::SUCCESS : Command::FAILURE, $status);
            self::assertSame($moves > 0, $realFilesystem->exists($firstTarget));
            self::assertSame($moves === 2, $realFilesystem->exists($secondTarget));
            self::assertSame($moves === 0, $realFilesystem->exists($first));
            self::assertSame($moves < 2, $realFilesystem->exists($second));
            $journals = glob($workspace . '/state/private-*/mutation-*.jsonl');
            self::assertIsArray($journals);
            self::assertCount(1, $journals);
            $contents = $realFilesystem->readFile($journals[0]);
            self::assertStringContainsString('"event":"run_started"', $contents);

            if ($failedEvent === null) {
                self::assertStringContainsString('"event":"completed"', $contents);
                self::assertStringContainsString('"event":"run_finished"', $contents);
            } elseif ($failedEvent === 'completed') {
                self::assertStringContainsString('"event":"intent"', $contents);
                self::assertStringNotContainsString('"event":"completed"', $contents);
                self::assertStringNotContainsString('"event":"run_finished"', $contents);

                // Reusing this application for a dry-run must not inherit the
                // finished mutation scope's failure or create another journal.
                self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'rename:exif', 'source' => $workspace . '/media', '--dry-run' => true]), new BufferedOutput()));
                self::assertSame(0, $result->executedMoves);
                self::assertSame(0, $result->runtimeErrors);
                self::assertSame($contents, $realFilesystem->readFile($journals[0]));
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Supplies both sides of the mutation boundary and a real successful control.
     *
     * @return iterable<string, array{string|null, int, int}> Journal append scenarios and actual success counts
     */
    public static function appendFailures(): iterable
    {
        yield 'intent cannot be persisted' => ['intent', 0, 4];
        yield 'move succeeds but receipt cannot be persisted' => ['completed', 1, 5];
        yield 'all real moves have persistent receipts' => [null, 2, 9];
        yield 'final run status cannot be persisted' => ['run_finished', 2, 9];
    }
}
