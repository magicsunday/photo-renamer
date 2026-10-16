<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\ContentClassifierInterface;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilderInterface;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilderInterface;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\PipelineReviewMapper;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use MagicSunday\Renamer\Test\Fixtures\TargetNameResolverFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function str_contains;
use function substr_count;

/**
 * Verifies the virtual command-orchestration flow for `rename:exif`.
 *
 * Unlike the pipeline-only harness, these tests exercise the command's own
 * preview, review, "nothing to do", and execution-boundary behavior. The
 * pipeline-facing collaborators are stubbed at clear boundaries. A filesystem
 * collector and executor use real temporary files; controlled native filesystem
 * failures verify actual mutation, diagnostics and command exit behavior.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class VirtualRenameExifCommandFlowTest extends TestCase
{
    use WorkspaceTrait;

    /** Real source directory used by command-only dry-run scenarios. */
    private string $workspace;

    /** Creates a source tree so the command uses the actual collector. */
    #[Override]
    protected function setUp(): void
    {
        $this->workspace = $this->createTempWorkspace();
        new Filesystem()->dumpFile($this->workspace . '/input-a.jpg', 'synthetic input');
    }

    /** Removes only this test's synthetic scan tree. */
    #[Override]
    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);
    }

    /**
     * A failed or policy-withheld companion remains in place after the still
     * moves. The command must identify paths, outcomes and a concrete reason.
     *
     * @param bool $withheld Whether policy blocks the companion instead of a storage failure
     *
     * @return void
     */
    #[Test]
    #[DataProvider('componentFailures')]
    public function partiallyMovedLivePhotoIsVisibleInCommandSummary(bool $withheld): void
    {
        $workspace       = $this->createTempWorkspace();
        $filesystem      = new Filesystem();
        $still           = $workspace . '/camera.jpg';
        $companion       = $workspace . '/camera.mov';
        $stillTarget     = $workspace . '/capture.jpg';
        $companionTarget = $workspace . '/capture.mov';
        $filesystem->dumpFile($still, 'synthetic still');
        $filesystem->dumpFile($companion, 'synthetic companion');

        $failingFilesystem = self::createPartialMock(Filesystem::class, ['rename']);
        $failingFilesystem->expects(self::exactly($withheld ? 1 : 2))->method('rename')->willReturnCallback(static function (string $source, string $target, bool $overwrite = false) use ($filesystem, $companion): void {
            if ($source === $companion) {
                throw new IOException("Controlled companion storage failure.\x1B[2J");
            }

            $filesystem->rename($source, $target, $overwrite);
        });
        $detailsOutput = new BufferedOutput();
        $reporter      = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $detailsOutput));
        $journal       = RuntimeExecutorFactory::createMutationJournal($workspace . '/state');
        $executor      = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $failingFilesystem, new RuntimeCollisionPathAllocator(), RuntimeExecutorFactory::createSourceIdentityGuard(), $journal), $journal);
        $plan          = new ExecutionPlan([new ExecutionGroup('synthetic pair', true, $still, [
            new ExecutionItem($still, $stillTarget, ExecutionItemType::Canonical, true, false, 'synthetic pair'),
            new ExecutionItem($companion, $companionTarget, ExecutionItemType::Companion, true, false, 'synthetic pair', isExecutable: !$withheld, executionBlockReason: $withheld ? 'Controlled policy block' : null),
        ])]);

        try {
            $journal->begin('rename:exif');
            [$command, $output] = $this->createCommandHarness(static function (PipelineContext $context): void {
                $context->setScannedFileCount(2);
            }, $plan, executor: $executor);
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);
            $status = $tester->execute(['source' => $workspace]);
            self::assertTrue($journal->finish($status));
            self::assertSame(Command::FAILURE, $status);
            self::assertSame('synthetic still', $filesystem->readFile($stillTarget));
            self::assertSame('synthetic companion', $filesystem->readFile($companion));
            self::assertFileDoesNotExist($still);
            self::assertFileDoesNotExist($companionTarget);
            $summary = $output->fetch();
            self::assertMatchesRegularExpression('/Renamed\s+1/', $summary);

            if ($withheld) {
                self::assertStringNotContainsString('Runtime errors', $summary);
            } else {
                self::assertMatchesRegularExpression('/Runtime errors\s+1/', $summary);
            }

            self::assertMatchesRegularExpression('/Partial Live Photo groups\s+1/', $summary);
            $details = $detailsOutput->fetch();
            self::assertFalse(str_contains($details, "\x1B"));
            self::assertStringContainsString('synthetic pair', $details);
            self::assertStringContainsString('canonical: Renamed', $details);
            self::assertStringContainsString($withheld
                ? 'companion: Withheld: Controlled policy block'
                : 'companion: Failed: Controlled companion storage failure.', $details);
            self::assertStringContainsString('Source: ' . $still, $details);
            self::assertStringContainsString('Current: ' . $stillTarget, $details);
            self::assertStringContainsString('Planned: ' . $companionTarget, $details);
            self::assertStringContainsString('Current: ' . $companion, $details);
            self::assertStringContainsString('Journal: ', $details);
            self::assertStringContainsString('No automatic rollback was performed.', $details);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * @return array<string, array{bool}> Actual filesystem failure and explicit policy withholding
     */
    public static function componentFailures(): array
    {
        return ['storage failure' => [false], 'policy withholding' => [true]];
    }

    /**
     * A capture can contain a primary pair and a separately named companion variant.
     * Every planned move and its no-op repeat must succeed without a partial warning.
     */
    #[Test]
    public function plannedCompanionVariantsRemainCompleteAfterRenameAndRepeat(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $sources    = [$workspace . '/camera.heic', $workspace . '/camera.mov', $workspace . '/variant.jpg', $workspace . '/variant.mov'];
        $targets    = [$workspace . '/capture.heic', $workspace . '/capture.mov', $workspace . '/capture-002.jpg', $workspace . '/capture-002.mov'];
        $types      = [ExecutionItemType::Canonical, ExecutionItemType::Companion, ExecutionItemType::Duplicate, ExecutionItemType::Companion];
        $contents   = ['synthetic still', 'synthetic primary video', 'synthetic edited still', 'synthetic alternate video'];

        foreach ($sources as $index => $source) {
            $filesystem->dumpFile($source, $contents[$index]);
        }

        $runtimeOutput = new BufferedOutput();
        $reporter      = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $runtimeOutput));
        $journal       = RuntimeExecutorFactory::createMutationJournal($workspace . '/state');
        $executor      = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), RuntimeExecutorFactory::createSourceIdentityGuard(), $journal), $journal);

        try {
            foreach ([false, true] as $repeat) {
                $items = [];

                foreach ($targets as $index => $target) {
                    $items[] = new ExecutionItem($repeat ? $target : $sources[$index], $target, $types[$index], !$repeat, $repeat, 'synthetic variants');
                }

                $plan               = new ExecutionPlan([new ExecutionGroup('synthetic variants', true, $repeat ? $targets[0] : $sources[0], $items)]);
                [$command, $output] = $this->createCommandHarness(static function (PipelineContext $context): void {
                    $context->setScannedFileCount(4);
                }, $plan, executor: $executor);
                $journal->begin('rename:exif');
                $journalPath = $journal->pathname();
                self::assertNotNull($journalPath);
                $tester = new CommandTester($command);
                $tester->setInputs(['yes']);
                $status = $tester->execute(['source' => $workspace]);
                self::assertTrue($journal->finish($status));
                self::assertSame(Command::SUCCESS, $status);
                self::assertSame(4, substr_count($filesystem->readFile($journalPath), '"event":"pair_member"'));
                self::assertStringNotContainsString('Partial Live Photo groups', $output->fetch());
                self::assertStringNotContainsString('incomplete', $runtimeOutput->fetch());

                foreach ($targets as $index => $target) {
                    self::assertSame($contents[$index], $filesystem->readFile($target));
                }
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A reserved MOV target makes the companion use a duplicate suffix after the
     * still successfully moves. Both operations succeed, but the pair's resulting
     * basenames diverge: the real command must return failure and expose that
     * incomplete pair instead of reporting an entirely successful batch.
     *
     * @return void
     */
    #[Test]
    public function separatedPairAfterSuccessfulFallbackStillReturnsFailure(): void
    {
        $workspace       = $this->createTempWorkspace();
        $filesystem      = new Filesystem();
        $still           = $workspace . '/camera.jpg';
        $companion       = $workspace . '/camera.mov';
        $stillTarget     = $workspace . '/capture.jpg';
        $companionTarget = $workspace . '/capture.mov';
        $canonical       = $workspace . '/canonical.heic';
        $filesystem->dumpFile($canonical, 'unchanged canonical still');
        $filesystem->dumpFile($still, 'synthetic still');
        $filesystem->dumpFile($companion, 'synthetic companion');
        $filesystem->dumpFile($companionTarget, 'reserved video');

        $reporter = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
        $journal  = RuntimeExecutorFactory::createMutationJournal();
        $executor = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), RuntimeExecutorFactory::createSourceIdentityGuard(), $journal), $journal);
        $plan     = new ExecutionPlan([
            new ExecutionGroup('reserved video', false, items: [
                new ExecutionItem($companionTarget, $companionTarget, ExecutionItemType::Canonical, false, true, 'reserved video', isExecutable: false),
            ]),
            new ExecutionGroup('synthetic pair', true, $canonical, [
                new ExecutionItem($canonical, $canonical, ExecutionItemType::Canonical, false, true, 'synthetic pair'),
                new ExecutionItem($still, $stillTarget, ExecutionItemType::Duplicate, true, false, 'synthetic pair'),
                new ExecutionItem($companion, $companionTarget, ExecutionItemType::Companion, true, false, 'synthetic pair'),
            ]),
        ]);

        try {
            [$command, $output] = $this->createCommandHarness(static function (PipelineContext $context): void {
                $context->setScannedFileCount(4);
            }, $plan, executor: $executor);
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);
            self::assertSame(Command::FAILURE, $tester->execute(['source' => $workspace]));
            self::assertSame('reserved video', $filesystem->readFile($companionTarget));
            self::assertSame('synthetic still', $filesystem->readFile($stillTarget));
            self::assertSame('synthetic companion', $filesystem->readFile($workspace . '/capture-duplicate-001.mov'));
            $summary = $output->fetch();
            self::assertMatchesRegularExpression('/Renamed\s+2/', $summary);
            self::assertMatchesRegularExpression('/Runtime fallbacks\s+1/', $summary);
            self::assertMatchesRegularExpression('/Partial Live Photo groups\s+1/', $summary);
            self::assertStringNotContainsString('Runtime errors', $summary);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Naming preserves each component's source directory. A still and MOV in
     * different subdirectories therefore remain a complete pair when both reach
     * their matching basenames; the partial counter must not require relocation
     * into one directory or turn this supported layout into a failed command.
     *
     * @return void
     */
    #[Test]
    public function completePairInDifferentSourceDirectoriesRemainsSuccessful(): void
    {
        $workspace       = $this->createTempWorkspace();
        $filesystem      = new Filesystem();
        $still           = $workspace . '/stills/camera.jpg';
        $companion       = $workspace . '/videos/camera.mov';
        $stillTarget     = $workspace . '/stills/capture.jpg';
        $companionTarget = $workspace . '/videos/capture.mov';
        $filesystem->dumpFile($still, 'synthetic still');
        $filesystem->dumpFile($companion, 'synthetic companion');

        $reporter = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
        $journal  = RuntimeExecutorFactory::createMutationJournal();
        $executor = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), RuntimeExecutorFactory::createSourceIdentityGuard(), $journal), $journal);
        $plan     = new ExecutionPlan([new ExecutionGroup('synthetic pair', true, $still, [
            new ExecutionItem($still, $stillTarget, ExecutionItemType::Canonical, true, false, 'synthetic pair'),
            new ExecutionItem($companion, $companionTarget, ExecutionItemType::Companion, true, false, 'synthetic pair'),
        ])]);

        try {
            [$command, $output] = $this->createCommandHarness(static function (PipelineContext $context): void {
                $context->setScannedFileCount(2);
            }, $plan, executor: $executor);
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);
            self::assertSame(Command::SUCCESS, $tester->execute(['source' => $workspace]));
            self::assertSame('synthetic still', $filesystem->readFile($stillTarget));
            self::assertSame('synthetic companion', $filesystem->readFile($companionTarget));
            $summary = $output->fetch();
            self::assertMatchesRegularExpression('/Renamed\s+2/', $summary);
            self::assertStringNotContainsString('Partial Live Photo groups', $summary);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A scan failure remains a failed run even when no moves can be planned;
     * operators must see the read-error count instead of a successful footer.
     */
    #[Test]
    public function readFailureProducesNonzeroExitAndSummary(): void
    {
        [$command, $output] = $this->createCommandHarness(
            function (PipelineContext $context): void {
                $context->setScannedFileCount(1);
                $context->addSkippedFile(new SkippedFile(new SplFileInfo($this->workspace . '/broken.jpg'), 'synthetic read failure', true));
            },
            new ExecutionPlan([]),
        );
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['source' => $this->workspace, '--dry-run' => true]));
        self::assertMatchesRegularExpression('/Skipped \(read errors\)\s+1/', $output->fetch());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    /**
     * Executes real moves around a missing source and a runtime target collision.
     * The command must report the successful fallback, preserve both contents,
     * continue after failure and return nonzero with observed summary counts.
     */
    #[Test]
    public function realMoveFailureAndFallbackAreReportedByCommand(): void
    {
        $workspace = $this->createTempWorkspace();
        file_put_contents($workspace . '/a.jpg', 'first');
        file_put_contents($workspace . '/b.jpg', 'second');
        $runtimeOutput = new BufferedOutput();
        $reporter      = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $runtimeOutput));
        $journal       = RuntimeExecutorFactory::createMutationJournal();
        $executor      = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, new Filesystem(), new RuntimeCollisionPathAllocator(), RuntimeExecutorFactory::createSourceIdentityGuard(), $journal), $journal);
        $items         = [
            new ExecutionItem($workspace . '/a.jpg', $workspace . '/shared.jpg', ExecutionItemType::Canonical, true, false, 'group'),
            new ExecutionItem($workspace . '/missing.jpg', $workspace . '/failed.jpg', ExecutionItemType::Canonical, true, false, 'group'),
            new ExecutionItem($workspace . '/b.jpg', $workspace . '/shared.jpg', ExecutionItemType::Canonical, true, false, 'group'),
        ];

        try {
            [$command, $output] = $this->createCommandHarness(
                static function (PipelineContext $context): void {
                    $context->setScannedFileCount(3);
                },
                new ExecutionPlan([new ExecutionGroup('group', false, null, $items)]),
                executor: $executor,
            );
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);
            self::assertSame(Command::FAILURE, $tester->execute(['source' => $workspace]));
            $summary = $output->fetch();
            self::assertMatchesRegularExpression('/Renamed\s+2/', $summary);
            self::assertMatchesRegularExpression('/Runtime errors\s+1/', $summary);
            self::assertMatchesRegularExpression('/Runtime fallbacks\s+1/', $summary);
            self::assertSame('first', file_get_contents($workspace . '/shared.jpg'));
            self::assertSame('second', file_get_contents($workspace . '/shared-duplicate-001.jpg'));
            self::assertFileDoesNotExist($workspace . '/a.jpg');
            self::assertFileDoesNotExist($workspace . '/b.jpg');
            self::assertFileDoesNotExist($workspace . '/failed.jpg');
            $diagnostics = $runtimeOutput->fetch();
            self::assertStringContainsString('Source file', $diagnostics);
            self::assertStringContainsString('b.jpg → shared-duplicate-001.jpg (planned: shared.jpg)', $diagnostics);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A single planned move whose actual filesystem rename throws must finish
     * with zero processed files, one runtime error and a nonzero command exit.
     * The real executor handles the injected IOException against an existing
     * temporary source; unchanged source bytes and an absent target prove that
     * neither the summary nor the success footer can claim a completed move.
     */
    #[Test]
    public function failedOnlyMoveReportsZeroProcessedAndPreservesSource(): void
    {
        $workspace = $this->createTempWorkspace();
        $source    = $workspace . '/source.jpg';
        $target    = $workspace . '/renamed.jpg';
        $contents  = 'synthetic source that must survive a storage failure';
        file_put_contents($source, $contents);

        $runtimeOutput = new BufferedOutput();
        $reporter      = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $runtimeOutput));
        $filesystem    = self::createPartialMock(Filesystem::class, ['rename']);
        $filesystem->expects(self::once())
            ->method('rename')
            ->with($source, $target)
            ->willThrowException(new IOException('Synthetic storage failure', 0, null, $target));
        $journal  = RuntimeExecutorFactory::createMutationJournal();
        $executor = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), RuntimeExecutorFactory::createSourceIdentityGuard(), $journal), $journal);
        $plan     = new ExecutionPlan([
            new ExecutionGroup('group', false, $source, [
                new ExecutionItem($source, $target, ExecutionItemType::Canonical, true, false, 'group'),
            ]),
        ]);

        try {
            [$command, $output] = $this->createCommandHarness(
                static function (PipelineContext $context): void {
                    $context->setScannedFileCount(1);
                },
                $plan,
                executor: $executor,
            );
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);

            self::assertSame(Command::FAILURE, $tester->execute(['source' => $workspace]));
            $summary = $output->fetch();
            self::assertMatchesRegularExpression('/Planned renames\s+1/', $summary);
            self::assertMatchesRegularExpression('/Renamed\s+0/', $summary);
            self::assertMatchesRegularExpression('/Runtime errors\s+1/', $summary);
            self::assertStringNotContainsString('[OK]', $tester->getDisplay());
            self::assertStringNotContainsString('[OK]', $summary);
            self::assertStringContainsString('file operation(s) failed', $summary);
            $diagnostics = $runtimeOutput->fetch();
            self::assertStringContainsString('Failed to rename', $diagnostics);
            self::assertStringContainsString('Synthetic storage failure', $diagnostics);
            self::assertStringNotContainsString('[OK]', $diagnostics);
            self::assertFileExists($source);
            self::assertSame($contents, file_get_contents($source));
            self::assertFileDoesNotExist($target);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that the command-level virtual harness maps review entries and
     * reaches the execution boundary in dry-run mode.
     *
     * The plan contains a real rename item so the command follows the normal
     * preview path, while the pipeline context contributes one conservative
     * cross-group video review finding that must be rendered via the mapper.
     */
    #[Test]
    public function virtualCommandFlowMapsReviewEntriesAndCallsExecutionBoundary(): void
    {
        [$command, $output] = $this->createCommandHarness(
            function (PipelineContext $context): void {
                $context->setScannedFileCount(2);
                $context->addVideoDuplicateCandidate(
                    new VideoDuplicateCandidate(
                        $this->workspace . '/review-a.mov',
                        $this->workspace . '/review-b.mov',
                        'video stream identical, audio differs',
                    ),
                );
            },
            new ExecutionPlan([
                new ExecutionGroup(
                    'group-1',
                    false,
                    $this->workspace . '/photo.jpg',
                    [
                        new ExecutionItem(
                            $this->workspace . '/photo.jpg',
                            $this->workspace . '/2024-01-01_10-00-00-000.jpg',
                            ExecutionItemType::Canonical,
                            true,
                            false,
                            'group-1',
                        ),
                    ],
                ),
            ]),
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source'     => $this->workspace,
            '--dry-run'  => true,
            '--list-all' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $commandDisplay = $tester->getDisplay();
        $buffer         = $output->fetch();

        self::assertStringContainsString('rename:exif', $commandDisplay);
        self::assertStringContainsString('Performing dry run. No files will be changed.', $commandDisplay);
        self::assertStringContainsString('Renaming files', $commandDisplay);
        self::assertStringContainsString('Video comparison requires review: video stream identical, audio differs', $buffer);
        self::assertStringContainsString('Compared with: review-b.mov', $buffer);
        self::assertStringContainsString('[WARNING]', $buffer);
        self::assertStringNotContainsString('[OK]', $buffer);
        self::assertStringContainsString('Scanned files', $buffer);
        self::assertStringContainsString('Planned renames', $buffer);
    }

    /**
     * Verifies that a no-op execution plan triggers the "Nothing to do" branch
     * while still reaching the non-mutating execution boundary once.
     *
     * This protects the command-specific orchestration path where preview counts
     * are zero and no skipped files exist, which is separate from the pure
     * pipeline semantics already covered by the virtual pipeline harness.
     */
    #[Test]
    public function virtualCommandFlowShowsNothingToDoForNoOpPlan(): void
    {
        [$command, $output] = $this->createCommandHarness(
            static function (PipelineContext $context): void {
                $context->setScannedFileCount(1);
            },
            new ExecutionPlan([
                new ExecutionGroup(
                    'group-1',
                    false,
                    $this->workspace . '/photo.jpg',
                    [
                        new ExecutionItem(
                            $this->workspace . '/photo.jpg',
                            $this->workspace . '/photo.jpg',
                            ExecutionItemType::Canonical,
                            false,
                            true,
                            'group-1',
                            isExecutable: false,
                        ),
                    ],
                ),
            ]),
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source'     => $this->workspace,
            '--dry-run'  => true,
            '--list-all' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $commandDisplay = $tester->getDisplay();
        $buffer         = $output->fetch();

        self::assertStringContainsString('All files already have the correct name. Nothing to do.', $commandDisplay);
        self::assertStringContainsString('[O] photo.jpg', $buffer);
        self::assertStringContainsString('Summary', $buffer);
        self::assertStringContainsString('Scanned files', $buffer);
    }

    /**
     * Multiple quality findings for one source count as one affected file;
     * a second skipped source remains distinct. The command retains its skip policy.
     */
    #[Test]
    public function scanSummarySeparatesDistinctFilesFromOverlappingFindings(): void
    {
        [$command] = $this->createCommandHarness(
            function (PipelineContext $context): void {
                $first  = $this->workspace . '/first.jpg';
                $second = $this->workspace . '/second.mov';
                $context->setScannedFileCount(2);
                $context->addFallbackDateFile($first);
                $context->addAmbiguousTimezoneFile($first);
                $context->addLivePhotoConflictFile($first, $second);
                $context->addSkippedFile(new SkippedFile(new SplFileInfo($first), 'Synthetic missing date'));
                $context->addSkippedFile(new SkippedFile(new SplFileInfo($second), 'Synthetic read error', true));
            },
            new ExecutionPlan([]),
        );
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['source' => $this->workspace, '--dry-run' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 affected file(s), 5 finding(s):', $display);
        self::assertStringContainsString('1 ambiguous timezone, 1 fallback date, 2 skipped, 1 LP conflict', $display);
    }

    /**
     * Creates the virtual command harness with a configurable pipeline context and plan.
     *
     * @param callable(PipelineContext): void $configureContext Callback that mutates the fresh pipeline context
     * @param ExecutionPlan                   $executionPlan    Execution plan returned by the builder stub
     * @param ExecutionPlanExecutor|null      $executor         Optional real executor for filesystem integration cases
     *
     * @return array{RenameByExifDateCommand, BufferedOutput} Command and captured renderer output
     */
    private function createCommandHarness(callable $configureContext, ExecutionPlan $executionPlan, ?ExecutionPlanExecutor $executor = null): array
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $captureGroupBuilder = new readonly class($configureContext) implements CaptureGroupBuilderInterface {
            /**
             * @param callable(PipelineContext): void $configureContext
             */
            public function __construct(
                private mixed $configureContext,
            ) {
            }

            public function build(
                RecursiveIteratorIterator $iterator,
                RenameStrategyInterface $renameStrategy,
                DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
                PipelineContext $context,
            ): AssetGroupCollection {
                ($this->configureContext)($context);

                return new AssetGroupCollection();
            }
        };

        $scorer              = new CanonicalScorer();
        $mediaTypeClassifier = new MediaTypeClassifier();
        $reporter            = new NullProgressReporter();
        $grouping            = self::createStub(ContentClassifierInterface::class);
        $visual              = self::createStub(PerceptualHashCalculatorInterface::class);

        $pipeline = new AssetGroupPipeline(
            $captureGroupBuilder,
            new SubgroupClassifier($grouping, $mediaTypeClassifier, new OrphanLivePhotoVideoReconciler($mediaTypeClassifier, $visual, $reporter), $reporter),
            new RoleAssigner($scorer, new CompanionDetector($mediaTypeClassifier), $mediaTypeClassifier),
            TargetNameResolverFactory::create(),
            new CollisionResolver(),
            new RenamePlanValidator(),
            $grouping,
            $visual,
            $scorer,
        );

        $executionPlanBuilder = new readonly class($executionPlan) implements ExecutionPlanBuilderInterface {
            public function __construct(
                private ExecutionPlan $executionPlan,
            ) {
            }

            /**
             * @param iterable<AssetGroup> $groups Validated group stream
             */
            public function build(iterable $groups, PipelineContext $context, ?int $maxDateDrift = null): ExecutionPlan
            {
                return $this->executionPlan;
            }
        };

        $command = new RenameByExifDateCommand(
            new FileCollector(new SafeRegex()),
            $executor ?? RuntimeExecutorFactory::createPlan($style),
            new SafeRegex(),
            new Filesystem(),
            new ExifMetadataProvider(new StubMetadataExtractor()),
            $pipeline,
            $executionPlanBuilder,
            new PipelineReviewMapper(),
            OutputRendererFactory::create($style),
            new TargetBasenameStrategy(),
        );

        return [$command, $output];
    }
}
