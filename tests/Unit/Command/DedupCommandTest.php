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
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\Dedup\DedupOriginalMatcher;
use MagicSunday\Renamer\Service\Dedup\DedupReportFormatter;
use MagicSunday\Renamer\Service\Dedup\DuplicateDeletionGuard;
use MagicSunday\Renamer\Service\Dedup\OriginalCandidateIndex;
use MagicSunday\Renamer\Service\Dedup\QuarantineTargetGuard;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\LegacyRenameExecutor;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FormatPriorityResolver;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Output\OutputEntryPresenter;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecider;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\CandidateOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\DefaultOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\FallbackOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\ReviewOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\WarningOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\SummaryRow;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
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
#[CoversClass(QuarantineTargetGuard::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MediaCompatibilityPolicy::class)]
#[UsesClass(DedupOriginalMatcher::class)]
#[UsesClass(DedupReportFormatter::class)]
#[UsesClass(DuplicateDeletionGuard::class)]
#[UsesClass(SafeHashCalculator::class)]
#[UsesClass(OriginalCandidateIndex::class)]
#[UsesClass(FormatPriorityResolver::class)]
#[UsesClass(RenameOutputRenderer::class)]
#[UsesClass(PathHelper::class)]
#[UsesClass(ExecutionPlanExecutor::class)]
#[UsesClass(FileCollector::class)]
#[UsesClass(LegacyRenameExecutor::class)]
#[UsesClass(RuntimeFileMoveExecutor::class)]
#[UsesClass(OutputEntryPresenter::class)]
#[UsesClass(OutputSkipReasonDecider::class)]
#[UsesClass(CandidateOutputSkipReasonRule::class)]
#[UsesClass(DefaultOutputSkipReasonRule::class)]
#[UsesClass(FallbackOutputSkipReasonRule::class)]
#[UsesClass(ReviewOutputSkipReasonRule::class)]
#[UsesClass(WarningOutputSkipReasonRule::class)]
#[UsesClass(SummaryRow::class)]
#[UsesClass(ConsoleProgressReporter::class)]
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
     * Existing links in the quarantine root, a nested configured target or a
     * mirrored source subdirectory must reject the whole batch before any file
     * moves. Both an ordinary root candidate and a nested candidate stay intact.
     *
     * @param string $linkedDirectory Target component redirected outside the source
     * @param bool   $dryRun          Whether to verify the same boundary in preview mode
     */
    #[Test]
    #[DataProvider('linkedQuarantineDirectories')]
    public function executeRejectsSymlinkQuarantineBeforeAnyMove(string $linkedDirectory, bool $dryRun): void
    {
        $workspace  = $this->createWorkspace();
        $filesystem = new Filesystem();
        $source     = $workspace . '/source';
        $outside    = $workspace . '/outside';
        $filesystem->mkdir([$source . '/2025', $outside]);
        file_put_contents($source . '/photo.jpg', 'original');
        file_put_contents($source . '/photo-duplicate-001.jpg', 'root candidate');
        file_put_contents($source . '/2025/clip.mov', 'original video');
        file_put_contents($source . '/2025/clip-duplicate-001.mov', 'nested candidate');
        file_put_contents($outside . '/sentinel', 'retained');
        $filesystem->mkdir($source . '/' . dirname($linkedDirectory));
        $filesystem->symlink($outside, $source . '/' . $linkedDirectory);

        try {
            $tester = new CommandTester($this->createCommand());
            $tester->setInputs(['yes']);
            $target = $linkedDirectory === 'review/_duplicates' ? 'review/_duplicates' : '_duplicates';

            self::assertSame(Command::FAILURE, $tester->execute(['source' => $source, '--target' => $target, '--dry-run' => $dryRun]));
            self::assertStringContainsString('Quarantine blocked', $tester->getDisplay());
            self::assertFileExists($source . '/photo-duplicate-001.jpg');
            self::assertFileExists($source . '/2025/clip-duplicate-001.mov');
            self::assertFileDoesNotExist($outside . '/photo-duplicate-001.jpg');
            self::assertFileDoesNotExist($outside . '/clip-duplicate-001.mov');
            self::assertSame('retained', $filesystem->readFile($outside . '/sentinel'));
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Supplies link positions that cover both explicit target components and
     * subdirectories derived from the source tree rather than CLI arguments.
     *
     * @return iterable<string, array{string, bool}> Linked relative directories and preview modes
     */
    public static function linkedQuarantineDirectories(): iterable
    {
        foreach ([false, true] as $dryRun) {
            $mode = $dryRun ? ' preview' : ' execute';

            yield 'root' . $mode => ['_duplicates', $dryRun];
            yield 'mirrored source directory' . $mode => ['_duplicates/2025', $dryRun];
            yield 'configured nested directory' . $mode => ['review/_duplicates', $dryRun];
        }
    }

    /**
     * Absolute, traversal and source-root target arguments cannot create a
     * quarantine outside the selected tree or silently move back into itself.
     * Invalid arguments must fail without creating even an empty target folder.
     *
     * @param string $target Unsafe target argument
     */
    #[Test]
    #[DataProvider('invalidQuarantineTargets')]
    public function executeRejectsInvalidQuarantineArgument(string $target): void
    {
        $workspace = $this->createWorkspace();
        file_put_contents($workspace . '/photo.jpg', 'original');
        file_put_contents($workspace . '/photo-duplicate-001.jpg', 'candidate');

        try {
            $tester = new CommandTester($this->createCommand());
            $tester->setInputs(['yes']);

            self::assertSame(Command::FAILURE, $tester->execute(['source' => $workspace, '--target' => $target]));
            self::assertStringContainsString('Quarantine blocked', $tester->getDisplay());
            self::assertFileExists($workspace . '/photo-duplicate-001.jpg');
            self::assertDirectoryDoesNotExist($workspace . '/_duplicates');
            self::assertDirectoryDoesNotExist($workspace . '/review');
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Covers Unix, Windows and UNC absolute paths, traversal before and after
     * normalization, embedded NULs, and empty/source-root destinations.
     *
     * @return iterable<string, array{string}> Unsafe target arguments
     */
    public static function invalidQuarantineTargets(): iterable
    {
        yield 'Unix absolute' => ['/outside'];
        yield 'Windows absolute' => ['C:\\outside'];
        yield 'Windows drive-relative' => ['C:outside'];
        yield 'UNC absolute' => ['\\\\server\\share'];
        yield 'parent' => ['../outside'];
        yield 'nested parent' => ['review/../../outside'];
        yield 'normalized parent' => ['review/../other'];
        yield 'Windows parent' => ['..\\outside'];
        yield 'empty' => [''];
        yield 'source root' => ['.'];
        yield 'dot root' => ['./'];
        yield 'NUL' => ["bad\0target"];
        yield 'home expansion' => ['~/outside'];
    }

    /**
     * Replaces the target with a link at the controlled mkdir boundary, after
     * scan and confirmation. The second check must prevent rename and retain
     * the candidate; no timing sleeps or hostile external paths are involved.
     */
    #[Test]
    public function executeRechecksQuarantineAfterDirectoryCreation(): void
    {
        $workspace      = $this->createWorkspace();
        $realFilesystem = new Filesystem();
        $source         = $workspace . '/source';
        $outside        = $workspace . '/outside';
        $realFilesystem->mkdir([$source, $outside]);
        file_put_contents($source . '/photo.jpg', 'original');
        file_put_contents($source . '/photo-duplicate-001.jpg', 'candidate');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('mkdir')->willReturnCallback(
            static function (string $directory) use ($outside, $realFilesystem): void {
                $realFilesystem->symlink($outside, $directory);
            },
        );
        $filesystem->expects(self::never())->method('rename');

        try {
            $tester = new CommandTester($this->createCommand($filesystem));
            $tester->setInputs(['yes']);

            self::assertSame(Command::FAILURE, $tester->execute(['source' => $source]));
            self::assertStringContainsString('Quarantine blocked', $tester->getDisplay());
            self::assertFileExists($source . '/photo-duplicate-001.jpg');
            self::assertFileDoesNotExist($outside . '/photo-duplicate-001.jpg');
            self::assertMatchesRegularExpression('/Duplicates found\s+0\R/', $tester->getDisplay());
            self::assertMatchesRegularExpression('/Space reclaimable\s+0 B\R/', $tester->getDisplay());
        } finally {
            $realFilesystem->remove($workspace);
        }
    }

    /**
     * A nested relative target preserves the source layout in preview and
     * execution, including native Unix backslashes in a source filename.
     */
    #[Test]
    public function executeAllowsNestedRelativeQuarantineAndDryRun(): void
    {
        $workspace  = $this->createWorkspace();
        $filesystem = new Filesystem();
        $filesystem->mkdir($workspace . '/2025');

        $original    = $workspace . '/2025/photo\\name.jpg';
        $duplicate   = $workspace . '/2025/photo\\name-duplicate-001.jpg';
        $destination = $workspace . '/review/duplicates/2025/photo\\name-duplicate-001.jpg';
        file_put_contents($original, 'original');
        file_put_contents($duplicate, 'candidate');

        try {
            $tester    = new CommandTester($this->createCommand());
            $arguments = ['source' => $workspace, '--target' => './review/duplicates/'];
            self::assertSame(Command::SUCCESS, $tester->execute([...$arguments, '--dry-run' => true]));
            self::assertFileExists($duplicate);
            self::assertDirectoryDoesNotExist($workspace . '/review');
            self::assertStringContainsString('Would move', $tester->getDisplay());

            $tester->setInputs(['yes']);
            self::assertSame(Command::SUCCESS, $tester->execute($arguments));
            self::assertFileDoesNotExist($duplicate);
            self::assertSame('candidate', $filesystem->readFile($destination));
            self::assertFileExists($original);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a duplicate marker cannot authorize deletion of different
     * bytes, even after the operator explicitly confirms the delete command.
     */
    #[Test]
    public function executeDeleteRejectsDifferentContent(): void
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

            self::assertSame(Command::FAILURE, $exitCode);

            self::assertStringContainsString('Deletion blocked', $tester->getDisplay());
            self::assertFileExists($duplicatePath);
            self::assertFileExists($originalPath);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Checks real deletion and dry-run against identical bytes. Dry-run must
     * expose verified evidence while retaining both files; execution retains
     * the original and removes only the matching duplicate.
     */
    #[Test]
    public function executeDeleteRequiresVerifiedBytesAndHonorsDryRun(): void
    {
        $workspace = $this->createWorkspace();
        $original  = $workspace . '/photo.jpg';
        $duplicate = $workspace . '/photo-duplicate-001.jpg';
        file_put_contents($original, 'identical-content');
        file_put_contents($duplicate, 'identical-content');

        try {
            $tester = new CommandTester($this->createCommand());
            self::assertSame(Command::SUCCESS, $tester->execute([
                'source' => $workspace, '--delete' => true, '--dry-run' => true,
            ]));
            self::assertStringContainsString('fresh byte identity verified', $tester->getDisplay());
            self::assertFileExists($duplicate);

            $tester->setInputs(['yes']);
            self::assertSame(Command::SUCCESS, $tester->execute([
                'source' => $workspace, '--delete' => true,
            ]));
            self::assertFileDoesNotExist($duplicate);
            self::assertFileExists($original);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Rejects a cross-format original in another directory when its bytes differ.
     * Neither a global name match nor format preference supplies deletion evidence,
     * and dry-run must describe the block without modifying either file.
     */
    #[Test]
    public function executeDeleteDryRunBlocksCrossDirectoryCrossFormatCandidate(): void
    {
        $workspace = $this->createWorkspace();
        mkdir($workspace . '/import');
        $original  = $workspace . '/import/photo.heic';
        $duplicate = $workspace . '/photo-duplicate-001.jpg';
        file_put_contents($original, 'original');
        file_put_contents($duplicate, 'modified');

        try {
            $tester = new CommandTester($this->createCommand());
            self::assertSame(Command::FAILURE, $tester->execute([
                'source' => $workspace, '--delete' => true, '--dry-run' => true,
            ]));
            self::assertStringContainsString('Deletion blocked', $tester->getDisplay());
            self::assertStringNotContainsString('Would delete', $tester->getDisplay());
            self::assertFileExists($duplicate);
            self::assertFileExists($original);
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

    /**
     * Wires the actual command collaborators, optionally replacing only the
     * mutation filesystem for controlled interleaving tests.
     *
     * @param Filesystem|null $filesystem Mutation filesystem or the normal implementation
     *
     * @return DedupCommand Fully wired command under test
     */
    private function createCommand(?Filesystem $filesystem = null): DedupCommand
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
            new DedupReportFormatter(),
            $renderer,
            $filesystem ?? new Filesystem(),
            new DuplicateDeletionGuard(new SafeHashCalculator()),
            new QuarantineTargetGuard(),
        );
    }
}
