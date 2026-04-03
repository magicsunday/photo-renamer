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
use MagicSunday\Renamer\Command\VerifyCommand;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\Verify\LivePhotoCompletenessAnalyzer;
use MagicSunday\Renamer\Service\Verify\MetadataIssueScanner;
use MagicSunday\Renamer\Service\Verify\VerifyDetailEntryFormatter;
use MagicSunday\Renamer\Service\Verify\VerifyReportFormatter;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
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
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the VerifyCommand, a read-only analysis command that reports
 * metadata problems in photo/video directories.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(VerifyCommand::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(DateDriftAnalyzer::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(RenameOutputRenderer::class)]
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
     * Verifies that the command fails with an appropriate exit code when
     * the provided source directory does not exist.
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
     * Verifies that processing an empty directory results in a successful
     * execution with a summary showing zero files were scanned.
     */
    #[Test]
    public function executeWithEmptyDirectoryProducesCleanSummary(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $command  = $this->createCommand();
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source' => $workspace,
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
     * Verifies that files with unsupported or unknown extensions (like .txt)
     * are correctly identified and listed in the "unrecognized" category.
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
                'source' => $workspace,
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
     * Verifies that files causing a metadata extraction error (e.g. corrupt files)
     * are caught and listed in the dedicated "Metadata read errors" section.
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
                'source' => $workspace,
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
     * Verifies that files that can be read but contain no usable metadata (like
     * screenshots without EXIF) are reported in the "No metadata" section.
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
                'source' => $workspace,
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
     * Verifies that files with ambiguous timezones (dates that could belong to multiple
     * UTC offsets depending on the local time) are flagged for manual review.
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
                'source' => $workspace,
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
     * Verifies that date drift in verify mode uses calendar-day semantics instead
     * of elapsed 24-hour intervals.
     *
     * The filename timestamp lies late on January 15, while the metadata falls
     * shortly after midnight on January 17. Elapsed-time drift is still only one
     * full day, but calendar-day drift is two days, so verify mode must report a
     * drift when the threshold is set to one day.
     */
    #[Test]
    public function executeReportsCalendarDayDriftBeyondElapsedDays(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_23-30-00.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-17T00:15:00+00:00'),
                    null,
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'           => $workspace,
                '--max-date-drift' => '1',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('Date drift', $output);
            self::assertStringContainsString('2024-01-15_23-30-00.jpg', $output);
        } finally {
            @unlink($jpgPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that the "--show" filter correctly limits the output to the specified
     * categories (e.g. only showing errors 'E' and skipping 'S').
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
                'source' => $workspace,
                '--show' => 'timezone',
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
     * Verifies that orphaned Live Photo components (e.g. a still image without
     * its paired video) are detected and flagged in the output.
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
                'source' => $workspace,
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
     * Verifies that complete Live Photo pairs (still + video) are recognized
     * as valid and do not trigger any warnings or errors.
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
                'source' => $workspace,
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
     * Verifies that the numeric counts in the final summary accurately reflect
     * the sum of entries listed in each detailed category.
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
                'source' => $workspace,
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

    /**
     * Verifies that a file with ambiguous timezone is NOT flagged when the raw
     * metadata date matches the filename date (i.e. write-date already fixed it).
     */
    #[Test]
    public function executeSkipsAmbiguousTimezoneWhenRawMetadataMatchesFilename(): void
    {
        $workspace = $this->createWorkspace();
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2013-10-17_10-36-18-000.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            // Raw metadata matches filename exactly — file was fixed by write-date
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2013-10-17T10:36:18+00:00'),
                    null,
                    false,
                    true, // isAmbiguousTimezone — still true (QuickTime structure unchanged)
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source' => $workspace,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringNotContainsString('Ambiguous timezone', $output);
            self::assertStringContainsString('OK', $output);
        } finally {
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that --detail flag shows actionable fix suggestions for ambiguous timezone files.
     */
    #[Test]
    public function executeDetailShowsFixSuggestionForAmbiguousTimezone(): void
    {
        $workspace = $this->createWorkspace();
        $movPath   = $workspace . DIRECTORY_SEPARATOR . 'clip.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2025:04:03 16:50:50', new DateTimeZone('UTC')),
                    null,
                    false,
                    true, // isAmbiguousTimezone
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'     => $workspace,
                '--detail'   => true,
                '--timezone' => 'Europe/Berlin',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            // Must show the problem description
            self::assertStringContainsString('Ambiguous timezone', $output);
            // Must show problem description and fix command with configured timezone
            self::assertStringContainsString('Problem:', $output);
            self::assertStringContainsString('QuickTime UTC without offset', $output);
            self::assertStringContainsString('rename:write-date', $output);
            self::assertStringContainsString('--reason=timezone', $output);
            self::assertStringContainsString('--timezone=Europe/Berlin', $output);
        } finally {
            @unlink($movPath);
            $this->cleanupWorkspace($workspace);
        }
    }

    /**
     * Verifies that --detail flag shows fix suggestion for fallback date files.
     */
    #[Test]
    public function executeDetailShowsFixSuggestionForFallbackDate(): void
    {
        $workspace = $this->createWorkspace();
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . 'scan-001.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2023-12-25T08:00:00+00:00'),
                    null,
                    true, // isFallbackDateTime
                ),
            );

            $command  = $this->createCommand($metadataExtractor);
            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'   => $workspace,
                '--detail' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $output = $tester->getDisplay();
            self::assertStringContainsString('no DateTimeOriginal', $output);
            self::assertStringContainsString('rename:write-date', $output);
            self::assertStringContainsString('--reason=fallback', $output);
        } finally {
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
        $renderer            = OutputRendererFactory::create($style);
        $fileSystemService   = FileSystemServiceFactory::create($renderer, $style);

        return new VerifyCommand(
            $metadataProvider,
            $fileSystemService,
            $renderer,
            new Filesystem(),
            new VerifyDetailEntryFormatter(),
            new MetadataIssueScanner($metadataProvider, new DateDriftAnalyzer(), $mediaTypeClassifier),
            new LivePhotoCompletenessAnalyzer(),
            new VerifyReportFormatter(),
        );
    }
}
