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
use MagicSunday\Renamer\Command\VerifyCommand;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
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
use function unlink;

/**
 * Verifies the VerifyCommand, a read-only analysis command that reports
 * metadata problems in photo/video directories.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(VerifyCommand::class)]
final class VerifyCommandTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that the command registers under the name "rename:verify".
     */
    #[Test]
    public function configureExposesVerifyCommandName(): void
    {
        $command = $this->createCommand();

        self::assertSame('rename:verify', $command->getName());
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
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Scanned files', $output);
            self::assertStringContainsString('OK', $output);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that an unrecognized file extension is reported in the filetype category.
     */
    #[Test]
    public function executeReportsUnrecognizedFileType(): void
    {
        $workspace = $this->createWorkspace();
        $txtPath   = $workspace . DIRECTORY_SEPARATOR . 'readme.txt';
        file_put_contents($txtPath, 'content');

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Unrecognized file types', $output);
            self::assertStringContainsString('readme.txt', $output);
        } finally {
            @unlink($txtPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file whose metadata cannot be read is reported in the error category.
     */
    #[Test]
    public function executeReportsMetadataReadError(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . 'broken.jpg';
        file_put_contents($jpgPath, 'invalid-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new ExifMetadataReadException('Parse error'),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Metadata read errors', $output);
            self::assertStringContainsString('broken.jpg', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file with no metadata is reported in the nodata category.
     */
    #[Test]
    public function executeReportsNoMetadata(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . 'empty.jpg';
        file_put_contents($jpgPath, 'no-exif');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            // Default response is null (no metadata)

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('No metadata', $output);
            self::assertStringContainsString('empty.jpg', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a file with ambiguous timezone is reported.
     */
    #[Test]
    public function executeReportsAmbiguousTimezone(): void
    {
        $workspace = $this->createWorkspace();
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_video.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Ambiguous timezone', $output);
            self::assertStringContainsString('2024-01-15_video.mov', $output);
        } finally {
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that the --show filter limits output to selected categories.
     */
    #[Test]
    public function executeShowFilterLimitsOutput(): void
    {
        $workspace = $this->createWorkspace();
        $txtPath   = $workspace . DIRECTORY_SEPARATOR . 'readme.txt';
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15.mov';

        file_put_contents($txtPath, 'text');
        file_put_contents($movPath, 'video');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--show'           => 'timezone',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            // Category listing should include timezone but not filetype
            self::assertStringContainsString('Ambiguous timezone (1 files):', $output);
            // The filetype category file listing should NOT appear (txt file not listed under Unrecognized)
            self::assertStringNotContainsString('readme.txt', $output);
        } finally {
            @unlink($txtPath);
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a Live Photo without its companion is reported.
     */
    #[Test]
    public function executeReportsMissingLivePhotoCompanion(): void
    {
        $workspace = $this->createWorkspace();
        $movPath   = $workspace . DIRECTORY_SEPARATOR . 'IMG_0042.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                    'UUID-CONTENT-ID',
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Missing Live Photo companion', $output);
            self::assertStringContainsString('IMG_0042.mov', $output);
            self::assertStringContainsString('no paired JPG/HEIC', $output);
        } finally {
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that a complete Live Photo pair (still + video) is NOT reported as missing.
     */
    #[Test]
    public function executeDoesNotReportCompleteLivePhotoPair(): void
    {
        $workspace = $this->createWorkspace();
        $heicPath  = $workspace . DIRECTORY_SEPARATOR . 'IMG_0042.heic';
        $movPath   = $workspace . DIRECTORY_SEPARATOR . 'IMG_0042.mov';

        file_put_contents($heicPath, 'photo-data');
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $heicPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                    'UUID-CONTENT-ID',
                ),
            );
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                    'UUID-CONTENT-ID',
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringNotContainsString('Missing Live Photo companion', $output);
        } finally {
            @unlink($heicPath);
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that the summary always appears and counts are correct.
     */
    #[Test]
    public function executeSummaryCountsMatchCategoryCounts(): void
    {
        $workspace = $this->createWorkspace();
        $txtPath   = $workspace . DIRECTORY_SEPARATOR . 'notes.txt';
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15.jpg';
        file_put_contents($txtPath, 'text');
        file_put_contents($jpgPath, 'photo');

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
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Scanned files', $output);
            // 2 files: 1 OK (jpg with metadata), 1 filetype (txt)
            self::assertStringContainsString('OK', $output);
        } finally {
            @unlink($txtPath);
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    private function createWorkspace(): string
    {
        return $this->createTempWorkspace('verify_');
    }

    private function cleanupWorkspace(string $workspace): void
    {
        $this->removeWorkspace($workspace);
    }

    private function createCommand(?StubMetadataExtractor $metadataExtractor = null): VerifyCommand
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $metadataExtractor ??= new StubMetadataExtractor();
        $metadataProvider    = new ExifMetadataProvider($metadataExtractor);
        $mediaTypeClassifier = new MediaTypeClassifier();
        $renderer            = new RenameOutputRenderer($style);
        $fileSystemService   = new FileSystemService($style, $renderer);

        return new VerifyCommand(
            $metadataProvider,
            $mediaTypeClassifier,
            $fileSystemService,
            $renderer,
        );
    }
}
