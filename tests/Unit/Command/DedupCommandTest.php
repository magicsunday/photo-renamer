<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\DedupCommand;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function str_repeat;

use const DIRECTORY_SEPARATOR;

/**
 * Tests for the DedupCommand which finds and removes files with
 * "-duplicate-" in their name.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DedupCommand::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(RenameOutputRenderer::class)]
final class DedupCommandTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that the command registers under the name "rename:dedup".
     */
    #[Test]
    public function configureExposesDedupCommandName(): void
    {
        $command = $this->createCommand();

        self::assertSame('rename:dedup', $command->getName());
    }

    /**
     * Verifies that dry-run mode finds duplicates and lists them without modifying files.
     */
    #[Test]
    public function executeDryRunFindsDuplicates(): void
    {
        $workspace = $this->createWorkspace();

        $subDir = $workspace . DIRECTORY_SEPARATOR . '2025';
        mkdir($subDir, 0755, true);

        $originalPath  = $subDir . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';
        $duplicatePath = $subDir . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, 'duplicate-content');

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Would move', $output);
            self::assertStringContainsString('2025-04-13_17-29-26-411-duplicate-001.jpg', $output);
            self::assertStringContainsString('Duplicates found', $output);

            // Files should still exist (dry-run)
            self::assertFileExists($duplicatePath);
            self::assertFileExists($originalPath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that orphaned duplicates (without a matching original) show a warning.
     */
    #[Test]
    public function executeDryRunSkipsOrphans(): void
    {
        $workspace = $this->createWorkspace();

        $orphanPath = $workspace . DIRECTORY_SEPARATOR . 'orphan-duplicate-001.jpg';
        file_put_contents($orphanPath, 'orphan-content');

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('[!]', $output);
            self::assertStringContainsString('Original not found', $output);
            self::assertStringContainsString('Orphaned (skipped)', $output);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that the summary includes space reclaimable information.
     */
    #[Test]
    public function executeDryRunShowsSpaceReclaimable(): void
    {
        $workspace = $this->createWorkspace();

        $originalPath  = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';
        $duplicatePath = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, str_repeat('x', 2048));

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Space reclaimable', $output);
            self::assertStringContainsString('KB', $output);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that duplicates are actually moved to the target directory.
     */
    #[Test]
    public function executeMoveDuplicatesToTargetDirectory(): void
    {
        $workspace = $this->createWorkspace();

        $subDir = $workspace . DIRECTORY_SEPARATOR . '2025';
        mkdir($subDir, 0755, true);

        $originalPath  = $subDir . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';
        $duplicatePath = $subDir . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, 'duplicate-content');

        try {
            $command = $this->createCommand();
            $tester  = new CommandTester($command);
            $tester->setInputs(['yes']);

            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            // File should be moved to _duplicates/2025/
            $movedPath = $workspace . DIRECTORY_SEPARATOR . '_duplicates'
                . DIRECTORY_SEPARATOR . '2025'
                . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

            self::assertFileExists($movedPath);
            self::assertFileDoesNotExist($duplicatePath);
            self::assertFileExists($originalPath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that --delete flag actually removes duplicate files.
     */
    #[Test]
    public function executeDeleteDuplicates(): void
    {
        $workspace = $this->createWorkspace();

        $originalPath  = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';
        $duplicatePath = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, 'duplicate-content');

        try {
            $command = $this->createCommand();
            $tester  = new CommandTester($command);
            $tester->setInputs(['yes']);

            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--delete'         => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('(deleted)', $output);

            // Duplicate should be deleted, original should remain
            self::assertFileDoesNotExist($duplicatePath);
            self::assertFileExists($originalPath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that --target option moves duplicates to a custom directory.
     */
    #[Test]
    public function executeCustomTarget(): void
    {
        $workspace = $this->createWorkspace();

        $originalPath  = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';
        $duplicatePath = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, 'duplicate-content');

        try {
            $command = $this->createCommand();
            $tester  = new CommandTester($command);
            $tester->setInputs(['yes']);

            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--target'         => '_trash',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $movedPath = $workspace . DIRECTORY_SEPARATOR . '_trash'
                . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

            self::assertFileExists($movedPath);
            self::assertFileDoesNotExist($duplicatePath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that non-dry-run execution requires confirmation (Fix 5).
     * When the user declines, no files are moved.
     */
    #[Test]
    public function executeNonDryRunRequiresConfirmation(): void
    {
        $workspace = $this->createWorkspace();

        $originalPath  = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';
        $duplicatePath = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, 'duplicate-content');

        try {
            $command = $this->createCommand();
            $tester  = new CommandTester($command);
            $tester->setInputs(['no']);

            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Are you sure', $output);
            // Duplicate file should still exist (not moved)
            self::assertFileExists($duplicatePath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a cross-directory duplicate (original in root, duplicate in
     * subdirectory) is correctly found and not flagged as orphaned (Fix 4).
     */
    #[Test]
    public function executeFindsCrossDirectoryOriginal(): void
    {
        $workspace = $this->createWorkspace();
        $subDir    = $workspace . DIRECTORY_SEPARATOR . 'backup';

        if (!is_dir($subDir)) {
            mkdir($subDir, 0777, true);
        }

        $originalPath  = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';
        $duplicatePath = $subDir . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, 'duplicate-content');

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            // Must NOT be flagged as orphaned
            self::assertStringNotContainsString('Original not found', $output);
            // Must be detected as a duplicate
            self::assertStringContainsString('[D]', $output);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    private function createWorkspace(): string
    {
        return $this->createTempWorkspace('dedup_');
    }

    private function cleanupWorkspace(string $workspace): void
    {
        $this->removeWorkspace($workspace);
    }

    private function createCommand(): DedupCommand
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $renderer          = new RenameOutputRenderer($style);
        $fileSystemService = new FileSystemService($style, $renderer);

        return new DedupCommand(
            $fileSystemService,
            $renderer,
        );
    }
}
