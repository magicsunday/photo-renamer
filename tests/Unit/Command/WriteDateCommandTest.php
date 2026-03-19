<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use DateTimeImmutable;
use MagicSunday\Renamer\Command\WriteDateCommand;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\ExiftoolWriter;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Tests for the WriteDateCommand.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(WriteDateCommand::class)]
final class WriteDateCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:write-date".
     */
    #[Test]
    public function configureExposesWriteDateCommandName(): void
    {
        $command = $this->createCommand();

        self::assertSame('rename:write-date', $command->getName());
    }

    /**
     * Verifies that an invalid source directory returns FAILURE.
     */
    #[Test]
    public function executeFailsForNonExistentDirectory(): void
    {
        $command  = $this->createCommand();
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => '/non-existent-path-' . uniqid('', true),
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    /**
     * Verifies that an empty directory produces a clean summary with zero scanned files.
     */
    #[Test]
    public function executeWithEmptyDirectoryProducesCleanSummary(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Scanned files', $output);
            self::assertStringContainsString('Already correct', $output);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file with no date in its name is counted as "no date in name".
     */
    #[Test]
    public function executeCountsFilesWithNoDateInName(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . 'IMG_1234.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('No date in name', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file with correct metadata is counted as "already correct".
     */
    #[Test]
    public function executeCountsAlreadyCorrectFiles(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T10:00:00+00:00'),
                    null,
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Already correct', $output);
            // Verify no per-file write entries (summary may show "Would write  0")
            self::assertStringNotContainsString('Would write:', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file with no metadata is detected as needing a write.
     */
    #[Test]
    public function executeDryRunDetectsNoMetadata(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15.jpg';
        file_put_contents($jpgPath, 'no-exif-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            // Default response is null (no metadata)

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Would write', $output);
            self::assertStringContainsString('no date in metadata', $output);
            self::assertStringContainsString('2024:01:15 00:00:00', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file with fallback DateTime is detected as needing a write.
     */
    #[Test]
    public function executeDryRunDetectsFallbackDateTime(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T10:00:00+00:00'),
                    null,
                    true, // isFallbackDateTime
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Would write', $output);
            self::assertStringContainsString('only ModifyDate (0x0132), no DateTimeOriginal', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file with QuickTime timestamp without timezone info is detected as needing a write.
     */
    #[Test]
    public function executeDryRunDetectsAmbiguousTimezone(): void
    {
        $workspace = $this->createWorkspace();
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_10-30-00.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T10:30:00+00:00'),
                    null,
                    false,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Would write', $output);
            self::assertStringContainsString('QuickTime timestamp without timezone info', $output);
        } finally {
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that date drift detection works in dry-run mode.
     */
    #[Test]
    public function executeDryRunDetectsDateDrift(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    // 30 days away from Jan 15
                    new DateTimeImmutable('2024-02-14T10:00:00+00:00'),
                    null,
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
                '--max-date-drift' => '7',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Would write', $output);
            self::assertStringContainsString('metadata date differs by', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    private function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('writedate_', true);

        if (!mkdir($workspace, 0o755) && !is_dir($workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        return $workspace;
    }

    private function cleanupWorkspace(string $workspace): void
    {
        @rmdir($workspace);
    }

    private function createCommand(?StubMetadataExtractor $metadataExtractor = null): WriteDateCommand
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $metadataExtractor ??= new StubMetadataExtractor();
        $metadataProvider    = new ExifMetadataProvider($metadataExtractor);
        $hashCalculator      = new SafeHashCalculator();
        $mediaTypeClassifier = new HashSubGroupingService($hashCalculator, $style);
        $fileSystemService   = new FileSystemService($style, new RenameOutputRenderer($style));
        $exiftoolWriter      = new ExiftoolWriter();

        return new WriteDateCommand(
            $metadataProvider,
            $mediaTypeClassifier,
            $fileSystemService,
            $exiftoolWriter,
        );
    }
}
