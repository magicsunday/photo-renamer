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
use DateTimeZone;
use MagicSunday\Renamer\Command\WriteDateCommand;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\ExiftoolWriter;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\MetadataCache;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function file_put_contents;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Tests for the WriteDateCommand.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(WriteDateCommand::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(LinkConfig::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(RenameOutputRenderer::class)]
final class WriteDateCommandTest extends TestCase
{
    use WorkspaceTrait;

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
     * Verifies that the command fails when exiftool is not available.
     */
    #[Test]
    public function executeFailsWhenExiftoolUnavailable(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $command  = $this->createCommandWithoutExiftool();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source' => $workspace,
            ]);

            self::assertSame(Command::FAILURE, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('exiftool is not installed', $output);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
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
            'source' => '/non-existent-path-' . uniqid('', true),
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
                'source'    => $workspace,
                '--dry-run' => true,
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
                'source'    => $workspace,
                '--dry-run' => true,
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
                'source'    => $workspace,
                '--dry-run' => true,
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
                'source'    => $workspace,
                '--dry-run' => true,
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
                'source'    => $workspace,
                '--dry-run' => true,
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
            // Metadata has a DIFFERENT time (UTC) than the filename (local) — ambiguous timezone
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T08:30:00+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'    => $workspace,
                '--dry-run' => true,
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
                'source'           => $workspace,
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

    /**
     * Verifies that a file whose raw metadata matches the filename date is treated
     * as "already correct" even when timezone conversion is active and would shift
     * the display time. This prevents re-writing files that were already fixed.
     */
    #[Test]
    public function executeDryRunSkipsAlreadyCorrectAmbiguousFile(): void
    {
        $workspace = $this->createWorkspace();
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2013-10-17_10-36-18-000.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            // Raw metadata matches the filename exactly (both 10:36:18),
            // but isAmbiguousTimezone is true — timezone conversion would shift it.
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2013-10-17T10:36:18+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $command = $this->createCommand($metadataExtractor);

            // Simulate TIMEZONE=Europe/Berlin being active
            $providerRef = new ReflectionProperty($command, 'exifMetadataProvider');
            $provider    = $providerRef->getValue($command);
            assert($provider instanceof ExifMetadataProvider);
            $provider->setDefaultTimezone(new DateTimeZone('Europe/Berlin'));

            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'    => $workspace,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Already correct', $output);
            // No per-file write entries — the [W] tag indicates a pending write
            self::assertStringNotContainsString('[W]', $output);
        } finally {
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    private function createWorkspace(): string
    {
        return $this->createTempWorkspace('writedate_');
    }

    private function cleanupWorkspace(string $workspace): void
    {
        $this->removeWorkspace($workspace);
    }

    /**
     * Verifies that non-dry-run execution requires confirmation (Fix 5).
     * When the user declines, no files are written.
     */
    #[Test]
    public function executeNonDryRunRequiresConfirmation(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15.jpg';
        file_put_contents($jpgPath, 'photo-data');

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
            // No writes should have happened
            self::assertStringNotContainsString('Written', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that AVI files are skipped with a warning because exiftool
     * cannot write QuickTime atoms to AVI RIFF containers (Fix 2).
     */
    #[Test]
    public function executeSkipsAviFilesWithWarning(): void
    {
        $workspace = $this->createWorkspace();
        $aviPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_10-00-00.avi';
        file_put_contents($aviPath, 'avi-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            // No metadata → would normally trigger a write

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'    => $workspace,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            // AVI must NOT appear as a write candidate
            self::assertStringNotContainsString('[W]', $output);
            // Should be counted as skipped unsupported write
            self::assertStringContainsString('Unsupported write', $output);
        } finally {
            @unlink($aviPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that --local-as-utc treats the existing CreateDate as local time,
     * writes Keys:CreationDate with offset AND corrects CreateDate to real UTC.
     * 16:34:58 "UTC" + Europe/Berlin → Keys:CreationDate=16:34:58+02:00, CreateDate=14:34:58 UTC.
     */
    #[Test]
    public function executeDryRunLocalAsUtcKeepsExistingTime(): void
    {
        $workspace = $this->createWorkspace();
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2014-04-26.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2014-04-26T15:43:33+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'         => $workspace,
                '--dry-run'      => true,
                '--reason'       => 'timezone',
                '--timezone'     => 'Europe/Berlin',
                '--local-as-utc' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            // Must show the ORIGINAL time (15:43:33), not converted (17:43:33)
            self::assertStringContainsString('15:43:33', $output);
            self::assertStringNotContainsString('17:43:33', $output);
        } finally {
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    private function createCommandWithoutExiftool(): WriteDateCommand
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $metadataExtractor   = new StubMetadataExtractor();
        $metadataProvider    = new ExifMetadataProvider($metadataExtractor);
        $mediaTypeClassifier = new MediaTypeClassifier();
        $renderer            = new RenameOutputRenderer($style);
        $fileSystemService   = new FileSystemService($style, $renderer);
        $exiftoolWriter      = new ExiftoolWriter();

        return new WriteDateCommand(
            $metadataProvider,
            $mediaTypeClassifier,
            $fileSystemService,
            $exiftoolWriter,
            $renderer,
            static fn (): bool => false,
        );
    }

    private function createCommand(?StubMetadataExtractor $metadataExtractor = null): WriteDateCommand
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $metadataExtractor ??= new StubMetadataExtractor();
        $metadataProvider    = new ExifMetadataProvider($metadataExtractor);
        $mediaTypeClassifier = new MediaTypeClassifier();
        $renderer            = new RenameOutputRenderer($style);
        $fileSystemService   = new FileSystemService($style, $renderer);
        $exiftoolWriter      = new ExiftoolWriter();

        return new WriteDateCommand(
            $metadataProvider,
            $mediaTypeClassifier,
            $fileSystemService,
            $exiftoolWriter,
            $renderer,
            static fn (): bool => true,
        );
    }
}
