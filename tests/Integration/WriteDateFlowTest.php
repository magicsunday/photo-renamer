<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Command\WriteDateCommand;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\MetadataExtractor;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionPreview;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use MagicSunday\Renamer\Service\ExiftoolWriter;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoBasenameTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetector;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTarget;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoExistingFilePathnameIndex;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDiffResult;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuildState;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\ExifRenamePipelineResult;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\PipelineReviewMapper;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Service\ValidationResult;
use MagicSunday\Renamer\Service\WriteDate\TimezoneRewritePlanner;
use MagicSunday\Renamer\Service\WriteDate\WriteDateCandidateAnalyzer;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonAnalyzer;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReportFormatter;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\ConsoleOutputParserTrait;
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
use Symfony\Component\Process\Process;

use function copy;

/**
 * Multi-step integration tests that exercise write-date → rename:exif flows.
 * Requires exiftool to be available in the test environment.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(WriteDateCommand::class)]
#[CoversClass(RenameByExifDateCommand::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(ExecutionGroup::class)]
#[UsesClass(ExecutionItem::class)]
#[UsesClass(ExecutionPlan::class)]
#[UsesClass(ExecutionPreview::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(OutputEntry::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(ExifMetadataReadException::class)]
#[UsesClass(HashComputationException::class)]
#[UsesClass(TargetFilenameException::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(MetadataExtractor::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(FileDuplicateCollection::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(LinkConfig::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(Rename::class)]
#[UsesClass(RenameOptions::class)]
#[UsesClass(RenameResult::class)]
#[UsesClass(SkippedFile::class)]
#[UsesClass(TargetFileResult::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(DuplicateDetectionService::class)]
#[UsesClass(ExiftoolWriter::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(HashSubGroupingService::class)]
#[UsesClass(LivePhotoBasenameTargetMap::class)]
#[UsesClass(LivePhotoPairingService::class)]
#[UsesClass(LivePhotoConflictDetector::class)]
#[UsesClass(LivePhotoContentIdentifierTarget::class)]
#[UsesClass(LivePhotoContentIdentifierTargetMap::class)]
#[UsesClass(LivePhotoExistingFilePathnameIndex::class)]
#[UsesClass(LivePhotoPairingCollection::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(LocalDifferenceAnalyzer::class)]
#[UsesClass(LocalDiffResult::class)]
#[UsesClass(PerceptualHashCalculator::class)]
#[UsesClass(PerceptualSignalCache::class)]
#[UsesClass(SimilarityClassification::class)]
#[UsesClass(SimilarityResult::class)]
#[UsesClass(ExecutionPlanBuilder::class)]
#[UsesClass(CanonicalScorer::class)]
#[UsesClass(CaptureGroupBuilder::class)]
#[UsesClass(CaptureGroupBuildState::class)]
#[UsesClass(AssetGroupPipeline::class)]
#[UsesClass(ExifRenamePipelineResult::class)]
#[UsesClass(CollisionResolver::class)]
#[UsesClass(CompanionDetector::class)]
#[UsesClass(RoleAssigner::class)]
#[UsesClass(SubgroupClassifier::class)]
#[UsesClass(TargetNameResolver::class)]
#[UsesClass(RenamePlanValidator::class)]
#[UsesClass(RenameOutputRenderer::class)]
#[UsesClass(SafeHashCalculator::class)]
#[UsesClass(TargetBasenameStrategy::class)]
#[UsesClass(ExifDateFilenameStrategy::class)]
#[UsesClass(Constants::class)]
final class WriteDateFlowTest extends TestCase
{
    use ConsoleOutputParserTrait;
    use WorkspaceTrait;

    private function testImagesDir(): string
    {
        return __DIR__ . '/../Fixtures/Images';
    }

    /**
     * Scenario 37: Correction of ambiguous timezones in videos.
     * Ensures that after writing the correct EXIF tags, the file is no longer
     * recognized as ambiguous in the next run.
     */
    #[Test]
    public function scenario37WriteDateTimezoneFlowFixesAmbiguousTz(): void
    {
        $workspace = $this->createTempWorkspace('writedate_flow_');

        try {
            // Copy scenario 33 (MOV with timezone) — but strip Keys:CreationDate to make it ambiguous
            $sourceDir = $this->testImagesDir() . '/06-ambiguous-timezone';
            $this->copyFiles($sourceDir, $workspace);

            // Step 1: Rename to UTC-based name (simulates user accepting [W] and renaming)
            $tags = $this->runRenameExifTags($workspace);
            self::assertNotEmpty($tags, 'File should appear as [W]');

            // The file MVI_1234.mov gets [W] — manually rename it to the UTC-based date
            // that the pipeline would produce if [W] weren't skipped
            $oldPath = $workspace . '/MVI_1234.mov';
            $newPath = $workspace . '/2024-02-14_19-30-00-000.mov';

            if (is_file($oldPath)) {
                rename($oldPath, $newPath);
            }

            // Step 2: Run write-date to fix the timezone
            $writeDateCommand = $this->createWriteDateCommand();
            $writeTester      = new CommandTester($writeDateCommand);
            $writeTester->setInputs(['yes']);

            $exitCode = $writeTester->execute([
                'source'     => $newPath,
                '--reason'   => 'timezone',
                '--timezone' => 'Europe/Berlin',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            // Step 3: Verify metadata was updated with timezone
            $tags = $this->runRenameExifTags($workspace);
            self::assertNotEmpty($tags, 'File should appear in output after timezone fix');

            foreach ($tags as $tag) {
                // After write-date fixes timezone, hasReliableDateTime should return true
                // because the raw metadata now matches the filename
                self::assertSame('O', $tag, 'File should be [O] after write-date timezone fix (metadata matches filename)');
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 38: Writing metadata to files without EXIF headers.
     * Verifies that a timestamp from the filename is successfully written
     * into the file.
     */
    #[Test]
    public function scenario38WriteDateNodataFlowWritesMetadata(): void
    {
        $workspace = $this->createTempWorkspace('writedate_flow_');

        try {
            // Create a JPG named with a date but with no EXIF metadata
            $filePath = $workspace . '/2025-06-15_14-30-00-000.jpg';
            copy($this->testImagesDir() . '/01-basic-rename/IMG_1234.jpg', $filePath);

            // Strip all date metadata
            $this->stripAllDates($filePath);

            // Step 1: Verify the file is [S] before write-date (no date in metadata)
            // [S] files don't appear in tag assignments (only in skipped list)
            $tags = $this->runRenameExifTags($workspace);
            // File with no metadata should have tag S or be absent from rename mappings
            $hasNoRenameTag = ($tags === []) || (isset($tags['2025-06-15_14-30-00-000.jpg']) && $tags['2025-06-15_14-30-00-000.jpg'] === 'S');
            self::assertTrue($hasNoRenameTag, 'File should be [S] (skipped) before write-date');

            // Step 2: Run write-date to write date from filename
            $writeDateCommand = $this->createWriteDateCommand();
            $writeTester      = new CommandTester($writeDateCommand);
            $writeTester->setInputs(['yes']);

            $exitCode = $writeTester->execute([
                'source'   => $workspace,
                '--reason' => 'nodata',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            // Step 3: Verify the file is now [O] (already correctly named)
            $tags = $this->runRenameExifTags($workspace);
            self::assertNotEmpty($tags, 'File should appear in output after write-date');

            foreach ($tags as $tag) {
                self::assertSame('O', $tag, 'File should be [O] after write-date (metadata matches filename)');
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 41: Validation of cache invalidation after writing.
     * Ensures that the metadata cache is cleared when the file on disk
     * changes, so that subsequent commands read current data.
     */
    #[Test]
    public function scenario41CacheInvalidationAfterWriteDate(): void
    {
        $workspace = $this->createTempWorkspace('writedate_flow_');

        try {
            // Create a JPG with a specific date
            $filePath = $workspace . '/2025-07-01_10-00-00-000.jpg';
            copy($this->testImagesDir() . '/01-basic-rename/IMG_1234.jpg', $filePath);

            // Step 1: Run rename:exif — should get [R] (IMG_1234 → 2024-06-15...)
            // Actually the file is already named 2025-07-01 but metadata says 2024-06-15
            $tags = $this->runRenameExifTags($workspace);

            // File has metadata date 2024-06-15 but filename says 2025-07-01 → [W] (date drift)
            // or [R] depending on drift threshold
            self::assertNotEmpty($tags);

            // Step 2: write-date to align metadata with filename
            $writeDateCommand = $this->createWriteDateCommand();
            $writeTester      = new CommandTester($writeDateCommand);
            $writeTester->setInputs(['yes']);

            $exitCode = $writeTester->execute([
                'source' => $filePath,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            // Step 3: rename:exif with fresh provider — should be [O] now
            $tags = $this->runRenameExifTags($workspace);
            self::assertNotEmpty($tags);

            foreach ($tags as $tag) {
                self::assertSame('O', $tag, 'File should be [O] after write-date (cache must be fresh)');
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Helper method to execute the `rename:exif` command and collect tag assignments.
     *
     * @param string $workspace The path to the test workspace
     *
     * @return array<string, string> source filename => tag letter
     */
    private function runRenameExifTags(string $workspace): array
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $mediaTypeClassifier      = new MediaTypeClassifier();
        $imageLoader              = new ImagickImageLoader($mediaTypeClassifier);
        $perceptualHashCalculator = new PerceptualHashCalculator($imageLoader);
        $progressReporter         = new ConsoleProgressReporter($style);

        $hashSubGroupingService = new HashSubGroupingService(
            new SafeHashCalculator(),
            $progressReporter,
            $mediaTypeClassifier,
            $perceptualHashCalculator,
            new LocalDifferenceAnalyzer(),
            $imageLoader,
        );

        $livePhotoConflictDetector = new LivePhotoConflictDetector($mediaTypeClassifier);

        $captureGroupBuilder = new CaptureGroupBuilder(
            $progressReporter,
            $mediaTypeClassifier,
            $livePhotoConflictDetector,
            new LivePhotoPairingService(),
        );
        $subgroupClassifier = new SubgroupClassifier(
            $hashSubGroupingService,
            $mediaTypeClassifier,
            new OrphanLivePhotoVideoReconciler($mediaTypeClassifier, $perceptualHashCalculator, $progressReporter),
            $progressReporter,
        );
        $mediaCompatibilityPolicy = new MediaCompatibilityPolicy($mediaTypeClassifier);
        $companionDetector        = new CompanionDetector($mediaCompatibilityPolicy);
        $canonicalScorer          = new CanonicalScorer();
        $roleAssigner             = new RoleAssigner($canonicalScorer, $companionDetector, $mediaCompatibilityPolicy);
        $targetNameResolver       = new TargetNameResolver();
        $collisionResolver        = new CollisionResolver();
        $renamePlanValidator      = new RenamePlanValidator();
        $executionPlanBuilder     = new ExecutionPlanBuilder();

        $pipeline = new AssetGroupPipeline(
            $captureGroupBuilder,
            $subgroupClassifier,
            $roleAssigner,
            $targetNameResolver,
            $collisionResolver,
            $renamePlanValidator,
        );

        $renderer = OutputRendererFactory::create($style);

        $command = new RenameByExifDateCommand(
            new FileSystemService($renderer, new ConsoleProgressReporter($style)),
            new DuplicateDetectionService(
                $progressReporter,
                $hashSubGroupingService,
                $mediaTypeClassifier,
                $livePhotoConflictDetector,
            ),
            new ExifMetadataProvider(new MetadataExtractor(MetadataReader::createDefault())),
            $perceptualHashCalculator,
            $hashSubGroupingService,
            $pipeline,
            $canonicalScorer,
            $executionPlanBuilder,
            new PipelineReviewMapper(),
            $renderer,
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source'     => $workspace,
            '--dry-run'  => true,
            '--list-all' => true,
            '--timezone' => 'Europe/Berlin',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        return $this->extractTagAssignments($output->fetch(), $workspace);
    }

    private function createWriteDateCommand(): WriteDateCommand
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $metadataProvider    = new ExifMetadataProvider(new MetadataExtractor(MetadataReader::createDefault()));
        $mediaTypeClassifier = new MediaTypeClassifier();
        $renderer            = OutputRendererFactory::create($style);
        $fileSystemService   = new FileSystemService($renderer, new ConsoleProgressReporter($style));

        return new WriteDateCommand(
            $metadataProvider,
            $fileSystemService,
            new ExiftoolWriter(),
            $renderer,
            new WriteDateCandidateAnalyzer(
                $metadataProvider,
                $mediaTypeClassifier,
                new WriteDateReasonAnalyzer($metadataProvider, new DateDriftAnalyzer(), $mediaTypeClassifier),
                new TimezoneRewritePlanner($metadataProvider),
            ),
            new WriteDateReportFormatter(),
            static fn (): bool => true,
        );
    }

    private function copyFiles(string $sourceDir, string $targetDir): void
    {
        $files = glob($sourceDir . '/*');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                copy($file, $targetDir . '/' . basename($file));
            }
        }
    }

    private function stripAllDates(string $filePath): void
    {
        $process = new Process([
            'exiftool', '-overwrite_original',
            '-DateTimeOriginal=',
            '-CreateDate=',
            '-ModifyDate=',
            '-SubSecTimeOriginal=',
            '-SubSecTime=',
            '-SubSecTimeDigitized=',
            $filePath,
        ]);
        $process->run();
    }
}
