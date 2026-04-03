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
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilderInterface;
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
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
use MagicSunday\Renamer\Test\Fixtures\VirtualFlow\FlatSplFileInfoRecursiveIterator;
use MagicSunday\Renamer\Test\Fixtures\VirtualFlow\SpyVirtualFileSystemService;
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

/**
 * Verifies the virtual command-orchestration flow for `rename:exif`.
 *
 * Unlike the pipeline-only harness, these tests exercise the command's own
 * preview, review, "nothing to do", and execution-boundary behavior while still
 * avoiding real filesystem mutation. The pipeline-facing collaborators are
 * stubbed at clear boundaries and the filesystem is replaced by a spy that
 * records the final `executePlan()` call.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class VirtualRenameExifCommandFlowTest extends TestCase
{
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
     *
     * @return array{RenameByExifDateCommand, SpyVirtualFileSystemService, BufferedOutput} Command, filesystem spy, and captured output
     */
    private function createCommandHarness(callable $configureContext, ExecutionPlan $executionPlan): array
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        /** @var RecursiveIterator<string, SplFileInfo> $flatIterator */
        $flatIterator = new FlatSplFileInfoRecursiveIterator([
            '/virtual/source/input-a.jpg' => new SplFileInfo('/virtual/source/input-a.jpg'),
        ]);
        /** @var RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> $iterator */
        $iterator = new RecursiveIteratorIterator($flatIterator);

        $fileSystemService = new SpyVirtualFileSystemService($iterator);

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

            public function build(AssetGroupCollection $groups, PipelineContext $context): ExecutionPlan
            {
                return $this->executionPlan;
            }
        };

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            self::createStub(DuplicateDetectionServiceInterface::class),
            new ExifMetadataProvider(new StubMetadataExtractor()),
            self::createStub(PerceptualHashCalculatorInterface::class),
            self::createStub(HashSubGroupingServiceInterface::class),
            $pipeline,
            new CanonicalScorer(),
            $executionPlanBuilder,
            new PipelineReviewMapper(),
            OutputRendererFactory::create($style),
        );

        return [$command, $fileSystemService, $output];
    }
}
