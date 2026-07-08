<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Filesystem;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the dedicated runtime executor for the ExecutionPlan path.
 * Uses real temp directories to exercise actual filesystem I/O.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExecutionPlanExecutor::class)]
#[UsesClass(ExecutionPlan::class)]
#[UsesClass(ExecutionGroup::class)]
#[UsesClass(ExecutionItem::class)]
#[UsesClass(ExecutionItemType::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(Constants::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(RuntimeCollisionPathAllocator::class)]
#[UsesClass(RuntimeFileMoveExecutor::class)]
final class ExecutionPlanExecutorTest extends TestCase
{
    use WorkspaceTrait;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('photo-renamer-ep-', true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);

        parent::tearDown();
    }

    /**
     * Verifies that an item with renameRequired=true causes the file to be
     * moved from source to target on disk.
     */
    #[Test]
    public function executePlanMovesFileWhenRenameRequired(): void
    {
        $executor = $this->createExecutor();

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-move';
        $targetDir = $this->workspace . DIRECTORY_SEPARATOR . 'target-move';
        mkdir($sourceDir);

        $sourceFile = $sourceDir . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'move-content');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-1',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceFile,
                        targetPath: $targetFile,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-1',
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan);

        self::assertFileDoesNotExist($sourceFile);
        self::assertFileExists($targetFile);
        self::assertSame('move-content', file_get_contents($targetFile));
        self::assertSame(1, $result->executedMoves);
        self::assertSame(0, $result->runtimeErrors);
    }

    /**
     * Verifies that an item with isNoOp=true is not moved. The file stays
     * at its source path (which equals its target path).
     */
    #[Test]
    public function executePlanSkipsNoOpItems(): void
    {
        $executor = $this->createExecutor();

        $directory = $this->workspace . DIRECTORY_SEPARATOR . 'noop';
        mkdir($directory);

        $file = $directory . DIRECTORY_SEPARATOR . 'already-correct.jpg';
        file_put_contents($file, 'noop-content');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-noop',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $file,
                        targetPath: $file,
                        type: ExecutionItemType::Canonical,
                        renameRequired: false,
                        isNoOp: true,
                        groupKey: 'group-noop',
                        isExecutable: false,
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan);

        self::assertFileExists($file);
        self::assertSame('noop-content', file_get_contents($file));
        self::assertSame(0, $result->executedMoves);
        self::assertSame(0, $result->runtimeErrors);
    }

    /**
     * Verifies that dry-run mode prevents any filesystem changes while still
     * returning correct counters.
     */
    #[Test]
    public function executePlanDryRunDoesNotMoveFiles(): void
    {
        $executor = $this->createExecutor();

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-dry';
        $targetDir = $this->workspace . DIRECTORY_SEPARATOR . 'target-dry';
        mkdir($sourceDir);

        $sourceFile = $sourceDir . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'dry-content');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-dry',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceFile,
                        targetPath: $targetFile,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-dry',
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan, dryRun: true);

        self::assertFileExists($sourceFile);
        self::assertFileDoesNotExist($targetFile);
        self::assertSame(0, $result->executedMoves);
        self::assertSame(0, $result->runtimeErrors);
    }

    /**
     * Verifies that when two items target the same path, the second file is
     * automatically redirected to a -duplicate-NNN fallback to prevent data loss.
     */
    #[Test]
    public function executePlanHandlesRuntimeCollision(): void
    {
        $executor = $this->createExecutor();

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-collision';
        $targetDir = $this->workspace . DIRECTORY_SEPARATOR . 'target-collision';
        mkdir($sourceDir);

        $sourceA      = $sourceDir . DIRECTORY_SEPARATOR . 'a.jpg';
        $sourceB      = $sourceDir . DIRECTORY_SEPARATOR . 'b.jpg';
        $sharedTarget = $targetDir . DIRECTORY_SEPARATOR . 'photo.jpg';

        file_put_contents($sourceA, 'content-A');
        file_put_contents($sourceB, 'content-B');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-a',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceA,
                        targetPath: $sharedTarget,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-a',
                    ),
                ],
            ),
            new ExecutionGroup(
                groupKey: 'group-b',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceB,
                        targetPath: $sharedTarget,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-b',
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan);

        // File A should land at the shared target
        self::assertFileDoesNotExist($sourceA);
        self::assertFileExists($sharedTarget);
        self::assertSame('content-A', file_get_contents($sharedTarget));

        // File B should be redirected to a -duplicate-NNN fallback
        self::assertFileDoesNotExist($sourceB);

        $fallbackTarget = $targetDir . DIRECTORY_SEPARATOR
            . 'photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

        self::assertFileExists($fallbackTarget);
        self::assertSame('content-B', file_get_contents($fallbackTarget));

        self::assertSame(2, $result->executedMoves);
        self::assertSame(1, $result->runtimeFallbacks);
    }

    /**
     * Verifies that items with isDuplicateTarget=true increment the duplicate counter.
     */
    #[Test]
    public function executePlanCountsDuplicateTargets(): void
    {
        $executor = $this->createExecutor();

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-dup-count';
        $targetDir = $this->workspace . DIRECTORY_SEPARATOR . 'target-dup-count';
        mkdir($sourceDir);

        $sourceFile = $sourceDir . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDir . DIRECTORY_SEPARATOR
            . sprintf('image%s001.jpg', Constants::DUPLICATE_IDENTIFIER);

        file_put_contents($sourceFile, 'dup-content');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-dup',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceFile,
                        targetPath: $targetFile,
                        type: ExecutionItemType::Duplicate,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-dup',
                        isDuplicateTarget: true,
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan);

        self::assertSame(1, $result->executedMoves);
        self::assertSame(0, $result->runtimeErrors);
    }

    /**
     * Verifies that ExecutionResult counters are correctly computed for a
     * mixed plan containing no-op, rename, and duplicate items.
     */
    #[Test]
    public function executePlanReturnsCorrectCounters(): void
    {
        $executor = $this->createExecutor();

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-counters';
        $targetDir = $this->workspace . DIRECTORY_SEPARATOR . 'target-counters';
        mkdir($sourceDir);

        $noOpFile     = $sourceDir . DIRECTORY_SEPARATOR . 'noop.jpg';
        $renameSource = $sourceDir . DIRECTORY_SEPARATOR . 'rename.jpg';
        $renameTarget = $targetDir . DIRECTORY_SEPARATOR . 'renamed.jpg';
        $dupSource    = $sourceDir . DIRECTORY_SEPARATOR . 'dup.jpg';
        $dupTarget    = $targetDir . DIRECTORY_SEPARATOR
            . sprintf('renamed%s001.jpg', Constants::DUPLICATE_IDENTIFIER);

        file_put_contents($noOpFile, 'noop');
        file_put_contents($renameSource, 'rename');
        file_put_contents($dupSource, 'duplicate');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-mixed',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $noOpFile,
                        targetPath: $noOpFile,
                        type: ExecutionItemType::Canonical,
                        renameRequired: false,
                        isNoOp: true,
                        groupKey: 'group-mixed',
                        isExecutable: false,
                    ),
                    new ExecutionItem(
                        sourcePath: $renameSource,
                        targetPath: $renameTarget,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-mixed',
                    ),
                    new ExecutionItem(
                        sourcePath: $dupSource,
                        targetPath: $dupTarget,
                        type: ExecutionItemType::Duplicate,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-mixed',
                        isDuplicateTarget: true,
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan);

        // Only 2 executable items (no-op is not executable)
        self::assertSame(2, $result->executedMoves);
        self::assertSame(0, $result->runtimeFallbacks);
        self::assertSame(0, $result->runtimeErrors);
    }

    /**
     * Verifies that an empty plan returns all-zero counters without errors.
     */
    #[Test]
    public function executePlanWithEmptyPlan(): void
    {
        $executor = $this->createExecutor();

        $plan = new ExecutionPlan([]);

        $result = $executor->executePlan($plan);

        self::assertSame(0, $result->executedMoves);
        self::assertSame(0, $result->runtimeFallbacks);
        self::assertSame(0, $result->runtimeErrors);
    }

    /**
     * Verifies that an item with isExecutable=false is NOT moved. The file
     * stays at its source path and the source path remains occupied.
     */
    #[Test]
    public function executePlanSkipsNonExecutableItems(): void
    {
        $executor = $this->createExecutor();

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-nonexec';
        mkdir($sourceDir);

        $sourceFile = $sourceDir . DIRECTORY_SEPARATOR . 'clip.mp4';
        $targetFile = $sourceDir . DIRECTORY_SEPARATOR . '2025-01-01_10-00-00-000.mp4';

        file_put_contents($sourceFile, 'blocked-content');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-nonexec',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceFile,
                        targetPath: $targetFile,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-nonexec',
                        isExecutable: false,
                        executionBlockReason: 'Ambiguous timezone: QuickTime UTC without offset',
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan);

        self::assertFileExists($sourceFile);
        self::assertFileDoesNotExist($targetFile);
        self::assertSame('blocked-content', file_get_contents($sourceFile));
        self::assertSame(0, $result->executedMoves);
        self::assertSame(0, $result->runtimeErrors);
    }

    /**
     * Verifies that non-executable items are not executed — neither blocked
     * items nor no-ops produce any executedMoves.
     */
    #[Test]
    public function executePlanSkipsAllNonExecutableItems(): void
    {
        $executor = $this->createExecutor();

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-skipcount';
        mkdir($sourceDir);

        $blockedFile = $sourceDir . DIRECTORY_SEPARATOR . 'clip.mp4';
        $noOpFile    = $sourceDir . DIRECTORY_SEPARATOR . 'already-correct.jpg';

        file_put_contents($blockedFile, 'blocked');
        file_put_contents($noOpFile, 'noop');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-skip',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $blockedFile,
                        targetPath: $sourceDir . DIRECTORY_SEPARATOR . '2025-01-01_10-00-00-000.mp4',
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-skip',
                        isExecutable: false,
                        executionBlockReason: 'Ambiguous timezone',
                    ),
                    new ExecutionItem(
                        sourcePath: $noOpFile,
                        targetPath: $noOpFile,
                        type: ExecutionItemType::Canonical,
                        renameRequired: false,
                        isNoOp: true,
                        groupKey: 'group-skip',
                        isExecutable: false,
                    ),
                ],
            ),
        ]);

        $result = $executor->executePlan($plan);

        self::assertSame(0, $result->executedMoves);
        self::assertSame(0, $result->runtimeFallbacks);
        self::assertSame(0, $result->runtimeErrors);
        // Both files unchanged on disk
        self::assertFileExists($blockedFile);
        self::assertFileExists($noOpFile);
    }

    /**
     * Verifies that a warning is emitted when runtime collision fallback changes
     * the target path (two items from different groups targeting the same path).
     */
    #[Test]
    public function executePlanLogsWarningOnRuntimeCollisionFallback(): void
    {
        $output   = new BufferedOutput();
        $io       = new SymfonyStyle(new ArrayInput([]), $output);
        $executor = $this->createExecutor($io);

        $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source-warn';
        $targetDir = $this->workspace . DIRECTORY_SEPARATOR . 'target-warn';
        mkdir($sourceDir);

        $sourceA      = $sourceDir . DIRECTORY_SEPARATOR . 'a.jpg';
        $sourceB      = $sourceDir . DIRECTORY_SEPARATOR . 'b.jpg';
        $sharedTarget = $targetDir . DIRECTORY_SEPARATOR . 'photo.jpg';

        file_put_contents($sourceA, 'content-A');
        file_put_contents($sourceB, 'content-B');

        $plan = new ExecutionPlan([
            new ExecutionGroup(
                groupKey: 'group-a',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceA,
                        targetPath: $sharedTarget,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-a',
                    ),
                ],
            ),
            new ExecutionGroup(
                groupKey: 'group-b',
                isLivePhotoGroup: false,
                items: [
                    new ExecutionItem(
                        sourcePath: $sourceB,
                        targetPath: $sharedTarget,
                        type: ExecutionItemType::Canonical,
                        renameRequired: true,
                        isNoOp: false,
                        groupKey: 'group-b',
                    ),
                ],
            ),
        ]);

        $executor->executePlan($plan);

        $outputText = $output->fetch();

        self::assertStringContainsString('Runtime collision fallback', $outputText);
    }

    private function createExecutor(?SymfonyStyle $io = null): ExecutionPlanExecutor
    {
        $io ??= new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $runtimeFileMoveExecutor = new RuntimeFileMoveExecutor(
            new ConsoleProgressReporter($io),
            new Filesystem(),
            new RuntimeCollisionPathAllocator(),
        );

        return new ExecutionPlanExecutor(new ConsoleProgressReporter($io), $runtimeFileMoveExecutor);
    }
}
