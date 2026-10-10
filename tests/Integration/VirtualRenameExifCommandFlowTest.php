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
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilderInterface;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilderInterface;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolverInterface;
use MagicSunday\Renamer\Service\Pipeline\PipelineReviewMapper;
use MagicSunday\Renamer\Service\Pipeline\RoleAssignerInterface;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifierInterface;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolverInterface;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
use MagicSunday\Renamer\Test\Fixtures\VirtualFlow\FlatSplFileInfoRecursiveIterator;
use MagicSunday\Renamer\Test\Fixtures\VirtualFlow\SpyVirtualFileSystemService;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIterator;
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

/**
 * Verifies the virtual command-orchestration flow for `rename:exif`.
 *
 * Unlike the pipeline-only harness, these tests exercise the command's own
 * preview, review, "nothing to do", and execution-boundary behavior. The
 * pipeline-facing collaborators are stubbed at clear boundaries. A filesystem
 * spy records the final `executePlan()` call and can delegate to the real
 * executor for failure and collision tests against temporary files.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class VirtualRenameExifCommandFlowTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A scan failure remains a failed run even when no moves can be planned;
     * operators must see the read-error count instead of a successful footer.
     */
    #[Test]
    public function readFailureProducesNonzeroExitAndSummary(): void
    {
        [$command, , $output] = $this->createCommandHarness(
            static function (PipelineContext $context): void {
                $context->setScannedFileCount(1);
                $context->addSkippedFile(new SkippedFile(new SplFileInfo('/virtual/source/broken.jpg'), 'synthetic read failure', true));
            },
            new ExecutionPlan([]),
        );
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['source' => '/virtual/source', '--dry-run' => true]));
        self::assertMatchesRegularExpression('/Skipped \(read errors\)\s+1/', $output->fetch());
        self::assertStringNotContainsString('[OK] done', $tester->getDisplay());
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
        $executor      = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, new Filesystem(), new RuntimeCollisionPathAllocator(), FileSystemServiceFactory::createSourceIdentityGuard()));
        $items         = [
            new ExecutionItem($workspace . '/a.jpg', $workspace . '/shared.jpg', ExecutionItemType::Canonical, true, false, 'group'),
            new ExecutionItem($workspace . '/missing.jpg', $workspace . '/failed.jpg', ExecutionItemType::Canonical, true, false, 'group'),
            new ExecutionItem($workspace . '/b.jpg', $workspace . '/shared.jpg', ExecutionItemType::Canonical, true, false, 'group'),
        ];

        try {
            [$command, , $output] = $this->createCommandHarness(
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
            self::assertMatchesRegularExpression('/Files processed\s+2/', $summary);
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
        $executor = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), FileSystemServiceFactory::createSourceIdentityGuard()));
        $plan     = new ExecutionPlan([
            new ExecutionGroup('group', false, $source, [
                new ExecutionItem($source, $target, ExecutionItemType::Canonical, true, false, 'group'),
            ]),
        ]);

        try {
            [$command, $fileSystemService, $output] = $this->createCommandHarness(
                static function (PipelineContext $context): void {
                    $context->setScannedFileCount(1);
                },
                $plan,
                executor: $executor,
            );
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);

            self::assertSame(Command::FAILURE, $tester->execute(['source' => $workspace]));
            self::assertSame(1, $fileSystemService->getExecutePlanCalls());
            self::assertFalse($fileSystemService->getCapturedDryRun());
            self::assertSame($plan, $fileSystemService->getCapturedExecutionPlan());
            $summary = $output->fetch();
            self::assertMatchesRegularExpression('/Planned moves\s+1/', $summary);
            self::assertMatchesRegularExpression('/Files processed\s+0/', $summary);
            self::assertMatchesRegularExpression('/Runtime errors\s+1/', $summary);
            self::assertStringNotContainsString('[OK] done', $tester->getDisplay());
            self::assertStringNotContainsString('[OK] done', $summary);
            $diagnostics = $runtimeOutput->fetch();
            self::assertStringContainsString('Failed to rename', $diagnostics);
            self::assertStringContainsString('Synthetic storage failure', $diagnostics);
            self::assertStringNotContainsString('[OK] done', $diagnostics);
            self::assertFileExists($source);
            self::assertSame($contents, file_get_contents($source));
            self::assertFileDoesNotExist($target);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A mixed executor result must reach the command exit status and summary:
     * two successful moves, one collision fallback and one failed operation
     * cannot be reported as three successfully processed files.
     */
    #[Test]
    public function mixedExecutionResultProducesFailureAndObservedCounts(): void
    {
        $items = [];

        foreach (['a', 'b', 'c'] as $name) {
            $items[] = new ExecutionItem('/virtual/source/' . $name . '.jpg', '/virtual/source/renamed-' . $name . '.jpg', ExecutionItemType::Canonical, true, false, 'group');
        }

        [$command, , $output] = $this->createCommandHarness(
            static function (PipelineContext $context): void {
                $context->setScannedFileCount(3);
            },
            new ExecutionPlan([new ExecutionGroup('group', false, null, $items)]),
            new ExecutionResult(executedMoves: 2, runtimeFallbacks: 1, runtimeErrors: 1),
        );
        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        self::assertSame(Command::FAILURE, $tester->execute(['source' => '/virtual/source']));
        self::assertStringNotContainsString('[OK] done', $tester->getDisplay());
        $summary = $output->fetch();
        self::assertMatchesRegularExpression('/Files processed\s+2/', $summary);
        self::assertMatchesRegularExpression('/Runtime errors\s+1/', $summary);
        self::assertMatchesRegularExpression('/Runtime fallbacks\s+1/', $summary);
        self::assertMatchesRegularExpression('/Planned moves\s+3/', $summary);
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
        [$command, $fileSystemService, $output] = $this->createCommandHarness(
            static function (PipelineContext $context): void {
                $context->setScannedFileCount(2);
                $context->addVideoDuplicateCandidate(
                    new VideoDuplicateCandidate(
                        '/virtual/source/review-a.mov',
                        '/virtual/source/review-b.mov',
                        'video stream identical, audio differs',
                    ),
                );
            },
            new ExecutionPlan([
                new ExecutionGroup(
                    'group-1',
                    false,
                    '/virtual/source/photo.jpg',
                    [
                        new ExecutionItem(
                            '/virtual/source/photo.jpg',
                            '/virtual/source/2024-01-01_10-00-00-000.jpg',
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
            'source'     => '/virtual/source',
            '--dry-run'  => true,
            '--list-all' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $fileSystemService->getExecutePlanCalls());
        self::assertTrue($fileSystemService->getCapturedDryRun());
        self::assertNotNull($fileSystemService->getCapturedExecutionPlan());
        self::assertSame(1, $fileSystemService->getCapturedExecutionPlan()->groupCount());

        $commandDisplay = $tester->getDisplay();
        $buffer         = $output->fetch();

        self::assertStringContainsString('rename:exif', $commandDisplay);
        self::assertStringContainsString('Performing dry run. No files will be changed.', $commandDisplay);
        self::assertStringContainsString('Renaming files', $commandDisplay);
        self::assertStringContainsString('Cross-group video review: review-b.mov — video stream identical, audio differs', $buffer);
        self::assertStringContainsString('Scanned files', $buffer);
        self::assertStringContainsString('Planned moves', $buffer);
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
        [$command, $fileSystemService, $output] = $this->createCommandHarness(
            static function (PipelineContext $context): void {
                $context->setScannedFileCount(1);
            },
            new ExecutionPlan([
                new ExecutionGroup(
                    'group-1',
                    false,
                    '/virtual/source/photo.jpg',
                    [
                        new ExecutionItem(
                            '/virtual/source/photo.jpg',
                            '/virtual/source/photo.jpg',
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
            'source'     => '/virtual/source',
            '--dry-run'  => true,
            '--list-all' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $fileSystemService->getExecutePlanCalls());
        self::assertTrue($fileSystemService->getCapturedDryRun());

        $commandDisplay = $tester->getDisplay();
        $buffer         = $output->fetch();

        self::assertStringContainsString('All files already have the correct name. Nothing to do.', $commandDisplay);
        self::assertStringContainsString('[O] photo.jpg', $buffer);
        self::assertStringContainsString('Summary', $buffer);
        self::assertStringContainsString('Scanned files', $buffer);
    }

    /**
     * Creates the virtual command harness with a configurable pipeline context and plan.
     *
     * @param callable(PipelineContext): void $configureContext Callback that mutates the fresh pipeline context
     * @param ExecutionPlan                   $executionPlan    Execution plan returned by the builder stub
     * @param ExecutionResult                 $executionResult  Observed result returned by the filesystem spy
     * @param ExecutionPlanExecutor|null      $executor         Optional real executor for filesystem integration cases
     *
     * @return array{RenameByExifDateCommand, SpyVirtualFileSystemService, BufferedOutput} Command, filesystem spy, and captured output
     */
    private function createCommandHarness(callable $configureContext, ExecutionPlan $executionPlan, ExecutionResult $executionResult = new ExecutionResult(), ?ExecutionPlanExecutor $executor = null): array
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        /** @var RecursiveIterator<string, SplFileInfo> $flatIterator */
        $flatIterator = new FlatSplFileInfoRecursiveIterator([
            '/virtual/source/input-a.jpg' => new SplFileInfo('/virtual/source/input-a.jpg'),
        ]);
        /** @var RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> $iterator */
        $iterator = new RecursiveIteratorIterator($flatIterator);

        $fileSystemService = new SpyVirtualFileSystemService($iterator, $executionResult, $executor);

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

        $pipeline = new AssetGroupPipeline(
            $captureGroupBuilder,
            self::createStub(SubgroupClassifierInterface::class),
            self::createStub(RoleAssignerInterface::class),
            self::createStub(TargetNameResolverInterface::class),
            self::createStub(CollisionResolverInterface::class),
            new RenamePlanValidator(),
        );

        $executionPlanBuilder = new readonly class($executionPlan) implements ExecutionPlanBuilderInterface {
            public function __construct(
                private ExecutionPlan $executionPlan,
            ) {
            }

            public function build(AssetGroupCollection $groups, PipelineContext $context, ?int $maxDateDrift = null): ExecutionPlan
            {
                return $this->executionPlan;
            }
        };

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
            new ExifMetadataProvider(new StubMetadataExtractor()),
            self::createStub(PerceptualHashCalculatorInterface::class),
            self::createStub(HashSubGroupingServiceInterface::class),
            $pipeline,
            new CanonicalScorer(),
            $executionPlanBuilder,
            new PipelineReviewMapper(),
            OutputRendererFactory::create($style),
            new TargetBasenameStrategy(),
        );

        return [$command, $fileSystemService, $output];
    }
}
