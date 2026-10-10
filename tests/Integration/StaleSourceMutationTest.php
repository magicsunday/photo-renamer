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
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function touch;

/**
 * Exercises an external edit at a deterministic boundary between command entry
 * and real plan execution. The application must preserve the analyzed identity
 * before command logic starts instead of accepting a fresh post-analysis state.
 */
#[CoversNothing]
final class StaleSourceMutationTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * The command body simulates analysis and then changes its source immediately
     * before executing a real plan. An unchanged control must still move. Both an inode replacement with identical
     * bytes and an in-place equal-size edit with restored mtime must invalidate
     * the plan, retain the externally changed source and report an actual error.
     *
     * @param string $changeKind Controlled external mutation after command entry
     *
     * @return void
     */
    #[Test]
    #[DataProvider('sourceChanges')]
    public function sourceChangedDuringAnalysisIsNeverRenamed(string $changeKind): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $source     = $workspace . '/media/source.jpg';
        $target     = $workspace . '/media/target.jpg';
        $filesystem->dumpFile($source, 'original content');

        $mtime    = new SplFileInfo($source)->getMTime();
        $reporter = new NullProgressReporter();
        $guard    = FileSystemServiceFactory::createSourceIdentityGuard();
        $executor = new ExecutionPlanExecutor($reporter, new RuntimeFileMoveExecutor($reporter, $filesystem, new RuntimeCollisionPathAllocator(), $guard));
        $result   = null;
        $command  = new Command('rename:exif');
        $command->addArgument('source', InputArgument::REQUIRED);
        $command->addOption('dry-run', 'd', InputOption::VALUE_NONE);
        $command->setCode(static function () use ($changeKind, $filesystem, $source, $target, $mtime, $executor, &$result): int {
            if ($changeKind === 'replace inode') {
                $filesystem->dumpFile($source, 'original content');
            } elseif ($changeKind === 'in-place edit') {
                file_put_contents($source, 'changed content!');
            }

            if ($changeKind !== 'unchanged') {
                touch($source, $mtime);
            }

            $plan = new ExecutionPlan([
                new ExecutionGroup('synthetic capture', false, items: [
                    new ExecutionItem($source, $target, ExecutionItemType::Canonical, true, false, 'synthetic capture'),
                ]),
            ]);
            $result = $executor->executePlan($plan);

            return $result->runtimeErrors > 0 ? Command::FAILURE : Command::SUCCESS;
        });
        $application = new Application([$command], new BatchRunLock($filesystem, $workspace . '/state'), $guard);
        $application->setAutoExit(false);

        try {
            $unchanged = $changeKind === 'unchanged';
            self::assertSame($unchanged ? Command::SUCCESS : Command::FAILURE, $application->run(new ArrayInput(['command' => 'rename:exif', 'source' => $workspace . '/media']), new BufferedOutput()));
            self::assertNotNull($result);
            self::assertSame($unchanged ? 1 : 0, $result->executedMoves);
            self::assertSame($unchanged ? 0 : 1, $result->runtimeErrors);

            if ($unchanged) {
                self::assertFileDoesNotExist($source);
                self::assertSame('original content', $filesystem->readFile($target));
            } else {
                self::assertFileDoesNotExist($target);
                self::assertSame($changeKind === 'replace inode' ? 'original content' : 'changed content!', $filesystem->readFile($source));
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Distinguishes pathname replacement from byte modification so neither a
     * content-only fingerprint nor pathname/size/mtime alone proves identity.
     *
     * @return iterable<string, array{string}> External source-change scenarios
     */
    public static function sourceChanges(): iterable
    {
        yield 'replacement with identical bytes' => ['replace inode'];
        yield 'equal-size in-place edit with restored mtime' => ['in-place edit'];
        yield 'unchanged source remains executable' => ['unchanged'];
    }
}
