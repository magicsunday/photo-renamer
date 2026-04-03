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
use MagicSunday\Renamer\Service\Dedup\DedupOriginalMatcher;
use MagicSunday\Renamer\Service\Dedup\OriginalCandidateIndex;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FormatPriorityResolver;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
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
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function preg_quote;
use function str_repeat;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

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
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MediaCompatibilityPolicy::class)]
#[UsesClass(DedupOriginalMatcher::class)]
#[UsesClass(OriginalCandidateIndex::class)]
#[UsesClass(FormatPriorityResolver::class)]
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
     * Verifies that the command correctly lists files with the "-duplicate-" marker
     * in dry-run mode, without actually moving or deleting them from the filesystem.
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
                'source'    => $workspace,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Would move', $output);
            self::assertStringContainsString('2025-04-13_17-29-26-411-duplicate-001.jpg', $output);
            self::assertStringContainsString('Duplicates found', $output);
            self::assertStringContainsString(
                '[D] 2025/2025-04-13_17-29-26-411-duplicate-001.jpg' . PHP_EOL
                . '     → Would move to _duplicates/2025/2025-04-13_17-29-26-411-duplicate-001.jpg',
                $output,
            );

            // Files should still exist (dry-run)
            self::assertFileExists($duplicatePath);
            self::assertFileExists($originalPath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that duplicate files without a corresponding original file (same name but without
     * "-duplicate-") are flagged with a warning instead of being treated as safe-to-remove.
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
                'source'    => $workspace,
                '--dry-run' => true,
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
     * Verifies that the summary at the end of execution correctly calculates and displays
     * the total file size that would be reclaimed by removing the detected duplicates.
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
                'source'    => $workspace,
                '--dry-run' => true,
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
     * Verifies that in non-dry-run mode, duplicates are moved to the default "_duplicates"
     * target directory when the user confirms the operation.
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
                'source' => $workspace,
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
     * Verifies that using the "--delete" option actually removes duplicate files from
     * the source directory instead of moving them to a target folder.
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
                'source'   => $workspace,
                '--delete' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString(
                '[D] 2025-04-13_17-29-26-411-duplicate-001.jpg' . PHP_EOL
                . '     → Deleted',
                $output,
            );

            // Duplicate should be deleted, original should remain
            self::assertFileDoesNotExist($duplicatePath);
            self::assertFileExists($originalPath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that the user can specify a custom target directory for moving duplicates,
     * overriding the default "_duplicates" folder name.
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
                'source'   => $workspace,
                '--target' => '_trash',
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
     * Verifies that the command halts and performs no operations if the user declines
     * the interactive confirmation prompt in non-dry-run mode.
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
                'source' => $workspace,
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
     * Verifies that original files are found even if they are in a different directory
     * than their duplicates, ensuring global matching within the source tree.
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
                'source'    => $workspace,
                '--dry-run' => true,
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

    /**
     * Verifies that a still-image duplicate is actionable even when the only
     * available original uses a different still format such as HEIC instead of JPG.
     */
    #[Test]
    public function executeFindsCrossExtensionStillOriginal(): void
    {
        $workspace = $this->createWorkspace();
        $subDir    = $workspace . DIRECTORY_SEPARATOR . 'backup';

        if (!is_dir($subDir)) {
            mkdir($subDir, 0777, true);
        }

        $originalPath  = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.heic';
        $duplicatePath = $subDir . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647-duplicate-001.jpg';

        file_put_contents($originalPath, 'original-content');
        file_put_contents($duplicatePath, 'duplicate-content');

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'    => $workspace,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringNotContainsString('Original not found', $output);
            self::assertStringContainsString('[D]', $output);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that the no-duplicate message is separated from the preceding scan/progress
     * output by two blank lines, making the "nothing to do" result visually stand apart.
     */
    #[Test]
    public function executeWithoutDuplicatesLeavesTwoBlankLinesBeforeNothingToDoMessage(): void
    {
        $workspace    = $this->createWorkspace();
        $originalPath = $workspace . DIRECTORY_SEPARATOR . '2025-04-13_17-29-26-411.jpg';

        file_put_contents($originalPath, 'original-content');

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'    => $workspace,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output  = $tester->getDisplay();
            $message = preg_quote('No duplicate files found — nothing to do.', '/');

            self::assertMatchesRegularExpression('/\R\R\R\s' . $message . '/u', $output);
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

        $renderer          = OutputRendererFactory::create($style);
        $fileSystemService = FileSystemServiceFactory::create($renderer, $style);
        $matcher           = new DedupOriginalMatcher(
            new MediaCompatibilityPolicy(new MediaTypeClassifier()),
        );

        return new DedupCommand(
            $fileSystemService,
            $matcher,
            $renderer,
            new Filesystem(),
        );
    }
}
