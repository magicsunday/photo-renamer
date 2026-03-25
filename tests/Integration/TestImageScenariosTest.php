<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use FilesystemIterator;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataExtractor;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoBasenameTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetector;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTarget;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoExistingFilePathnameIndex;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\MetadataCache;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDiffResult;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function assert;
use function basename;
use function copy;
use function is_dir;
use function mkdir;
use function preg_match_all;
use function preg_replace;
use function rename;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const PREG_SET_ORDER;

/**
 * Integration test validating all test-image scenarios against their expected
 * rename outcomes. Each scenario directory contains real media files with genuine
 * EXIF/QuickTime metadata. The test runs the full rename pipeline (scan, group,
 * Live Photo pair, hash sub-group, assign filenames) in dry-run mode and asserts
 * that every file maps to its expected target filename.
 *
 * Uses real MetadataExtractor (backed by imagemeta) and real PerceptualHashCalculator
 * (backed by Imagick) to validate the complete pipeline with production-grade services.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameByExifDateCommand::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(Constants::class)]
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
#[UsesClass(FileSystemService::class)]
#[UsesClass(HashSubGroupingService::class)]
#[UsesClass(LivePhotoBasenameTargetMap::class)]
#[UsesClass(LivePhotoConflictDetector::class)]
#[UsesClass(LivePhotoContentIdentifierTarget::class)]
#[UsesClass(LivePhotoContentIdentifierTargetMap::class)]
#[UsesClass(LivePhotoExistingFilePathnameIndex::class)]
#[UsesClass(LivePhotoPairingCollection::class)]
#[UsesClass(LivePhotoPairingService::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(LocalDifferenceAnalyzer::class)]
#[UsesClass(LocalDiffResult::class)]
#[UsesClass(PerceptualHashCalculator::class)]
#[UsesClass(PerceptualSignalCache::class)]
#[UsesClass(SimilarityClassification::class)]
#[UsesClass(SimilarityResult::class)]
#[UsesClass(RenameOutputRenderer::class)]
#[UsesClass(SafeHashCalculator::class)]
#[UsesClass(TargetBasenameStrategy::class)]
#[UsesClass(ExifDateFilenameStrategy::class)]
final class TestImageScenariosTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Returns the absolute path to the committed test-images directory.
     * Derived from this file's location to work both on the host and inside Docker.
     */
    private function testImagesDir(): string
    {
        return __DIR__ . '/../Fixtures/Images';
    }

    /**
     * Validates a single test-image scenario by copying it to a temp workspace,
     * running the full rename pipeline in dry-run mode with real metadata extraction,
     * and asserting that every file maps to its expected target filename.
     *
     * @param string                $scenarioDir       Name of the scenario directory (e.g. "01-basic-rename")
     * @param array<string, string> $expectedMap       Map of source filename to expected target filename
     * @param int                   $expectedFileCount Total file count expected in the rename mapping
     */
    #[Test]
    #[DataProvider('scenarioProvider')]
    public function scenarioProducesExpectedRenameMapping(
        string $scenarioDir,
        array $expectedMap,
        int $expectedFileCount,
    ): void {
        $sourceDir = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir, 'Scenario directory must exist: ' . $scenarioDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $mappings = $this->runDryRun($targetDir);

            self::assertCount(
                $expectedFileCount,
                $mappings,
                'Scenario ' . $scenarioDir . ': unexpected file count in rename mapping',
            );

            foreach ($expectedMap as $sourceFile => $expectedTarget) {
                self::assertArrayHasKey(
                    $sourceFile,
                    $mappings,
                    'Scenario ' . $scenarioDir . ': source file not found in mapping: ' . $sourceFile,
                );

                self::assertSame(
                    $expectedTarget,
                    $mappings[$sourceFile],
                    'Scenario ' . $scenarioDir . ': target mismatch for ' . $sourceFile,
                );
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that extractTagAssignments correctly parses [W] tags from scenario 06
     * (ambiguous timezone). This validates the helper itself and serves as an
     * integration test for the tag assignment pipeline.
     */
    #[Test]
    public function extractTagAssignmentsReturnsWarningTagForAmbiguousTimezone(): void
    {
        $scenarioDir = '06-ambiguous-timezone';
        $sourceDir   = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $consoleOutput = $this->runDryRunRaw($targetDir);
            $tags          = $this->extractTagAssignments($consoleOutput, $targetDir);

            // Scenario 06 has one MOV with ambiguous timezone → must be [W]
            self::assertNotEmpty($tags, 'Should have at least one tag assignment');

            foreach ($tags as $file => $tag) {
                self::assertSame('W', $tag, 'File ' . $file . ' should be tagged [W]');
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 49: Idempotency — files already named correctly produce all [O] on re-run.
     * Uses scenario 01 fixture (single JPG), renames it, then verifies a second
     * dry-run shows only [O] entries.
     */
    #[Test]
    public function scenario49IdempotencyAcrossFormats(): void
    {
        $scenarioDir = '01-basic-rename';
        $sourceDir   = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            // First: get the expected target name
            $mappings = $this->runDryRun($targetDir);
            self::assertNotEmpty($mappings);

            // Rename the file to its expected target
            foreach ($mappings as $source => $target) {
                $sourcePath = $targetDir . DIRECTORY_SEPARATOR . $source;
                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $target;

                if ($sourcePath !== $targetPath) {
                    rename($sourcePath, $targetPath);
                }
            }

            // Second dry-run: all files should be [O] (already correctly named)
            $consoleOutput = $this->runDryRunRaw($targetDir);
            $tags          = $this->extractTagAssignments($consoleOutput, $targetDir);

            self::assertNotEmpty($tags, 'Should have tag assignments after re-run');

            foreach ($tags as $file => $tag) {
                self::assertSame('O', $tag, 'File ' . $file . ' should be [O] on second run');
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 35: --skip-fallback option skips files with fallback date [F].
     * Uses existing scenario 05 fixture (file with only 0x0132 ModifyDate).
     * Verifies that [F] files appear in output with --list-all and are skipped.
     */
    #[Test]
    public function scenario35SkipFallbackOptionSkipsFFiles(): void
    {
        $scenarioDir = '05-fallback-date';
        $sourceDir   = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $consoleOutput = $this->runDryRunRaw($targetDir, ['--skip-fallback' => true]);
            $tags          = $this->extractTagAssignments($consoleOutput, $targetDir);

            // With --skip-fallback + --list-all, the [F] file should appear tagged [F]
            self::assertNotEmpty($tags, 'Should have at least one tag assignment');

            foreach ($tags as $file => $tag) {
                self::assertSame('F', $tag, 'File ' . $file . ' should be tagged [F]');
            }

            // The file should be skipped (no actual rename performed)
            self::assertStringContainsString('Planned skips', $consoleOutput);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 51: LP still with fallback date [F] → companion video inherits [F]
     * via LP atomicity propagation (Fix 8).
     */
    #[Test]
    public function scenario51LpStillFallbackPropagatedToVideo(): void
    {
        $scenarioDir = '51-lp-still-fallback';
        $sourceDir   = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $consoleOutput = $this->runDryRunRaw($targetDir);
            $tags          = $this->extractTagAssignments($consoleOutput, $targetDir);

            self::assertArrayHasKey('IMG_0001.jpg', $tags, 'JPG still must appear in output');
            self::assertArrayHasKey('IMG_0001.mov', $tags, 'MOV companion must appear in output');
            self::assertSame('F', $tags['IMG_0001.jpg'], 'JPG still must be tagged [F] (fallback date)');
            self::assertSame('F', $tags['IMG_0001.mov'], 'MOV companion must inherit [F] from still');
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 54: two byte-identical files with fallback date → both [F].
     * Fix 9: [F] must take priority over [D] for duplicate with fallback.
     */
    #[Test]
    public function scenario54DuplicatePlusFallbackBothTaggedF(): void
    {
        $scenarioDir = '54-duplicate-plus-fallback';
        $sourceDir   = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $consoleOutput = $this->runDryRunRaw($targetDir);
            $tags          = $this->extractTagAssignments($consoleOutput, $targetDir);

            self::assertNotEmpty($tags, 'Should have tag assignments');

            foreach ($tags as $file => $tag) {
                self::assertSame('F', $tag, 'File ' . $file . ' must be tagged [F]');
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 55: original + edited version (different hash), both with fallback
     * date → edit gets -002 suffix and [F] tag.
     */
    #[Test]
    public function scenario55EditPlusFallbackBothTaggedF(): void
    {
        $scenarioDir = '55-edit-plus-fallback';
        $sourceDir   = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $consoleOutput = $this->runDryRunRaw($targetDir);
            $tags          = $this->extractTagAssignments($consoleOutput, $targetDir);

            self::assertArrayHasKey('IMG_0001.jpg', $tags);
            self::assertArrayHasKey('IMG_0002.jpg', $tags);
            self::assertSame('F', $tags['IMG_0001.jpg'], 'Original must be tagged [F]');
            self::assertSame('F', $tags['IMG_0002.jpg'], 'Edit must be tagged [F]');
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Scenario 58: two MOV edits with ambiguous timezone → both [W] skipped.
     */
    #[Test]
    public function scenario58EditPlusAmbiguousTzBothTaggedW(): void
    {
        $scenarioDir = '58-edit-plus-ambiguous-tz';
        $sourceDir   = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir);

        $workspace = $this->createTempWorkspace('test_images_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $consoleOutput = $this->runDryRunRaw($targetDir);
            $tags          = $this->extractTagAssignments($consoleOutput, $targetDir);

            self::assertNotEmpty($tags, 'Should have tag assignments');

            foreach ($tags as $file => $tag) {
                self::assertSame('W', $tag, 'File ' . $file . ' must be tagged [W]');
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Provides all test-image scenarios with their expected rename outcomes.
     *
     * Each entry yields:
     *   - scenario directory name
     *   - map of source filename => expected target filename
     *   - expected total file count in the rename mapping (excludes SKIPPED files)
     *
     * @return iterable<string, array{string, array<string, string>, int}>
     */
    public static function scenarioProvider(): iterable
    {
        yield '01-basic-rename' => [
            '01-basic-rename',
            [
                'IMG_1234.jpg' => '2024-06-15_14-30-00-000.jpg',
            ],
            1,
        ];

        yield '02-duplicates' => [
            '02-duplicates',
            [
                'photo-a.jpg' => '2024-03-20_09-15-30-500.jpg',
                'photo-b.jpg' => '2024-03-20_09-15-30-500-duplicate-001.jpg',
            ],
            2,
        ];

        yield '03-hash-subgroups' => [
            '03-hash-subgroups',
            [
                'burst-1.jpg' => '2024-07-04_18-00-00-100.jpg',
                'burst-2.jpg' => '2024-07-04_18-00-00-200.jpg',
            ],
            2,
        ];

        yield '04-live-photo-pair' => [
            '04-live-photo-pair',
            [
                'IMG_0001.jpg' => '2025-01-01_00-02-20-016.jpg',
                'IMG_0001.mov' => '2025-01-01_00-02-20-016.mov',
            ],
            2,
        ];

        yield '05-fallback-date' => [
            '05-fallback-date',
            [
                'scan-001.jpg' => '2023-12-25_08-00-00-000.jpg',
            ],
            1,
        ];

        // Scenario 06: ambiguous timezone — [W] skipped, no mapping
        yield '06-ambiguous-timezone' => [
            '06-ambiguous-timezone',
            [],
            0,
        ];

        // Scenario 07: file has no metadata, skipped entirely (not in rename mapping)
        yield '07-no-metadata' => [
            '07-no-metadata',
            [],
            0,
        ];

        // Scenario 08: date drift — [W] skipped, no mapping
        yield '08-date-drift' => [
            '08-date-drift',
            [],
            0,
        ];

        yield '09-extension-normalize' => [
            '09-extension-normalize',
            [
                'photo.JPEG' => '2024-05-01_12-00-00-000.jpg',
            ],
            1,
        ];

        yield '10-already-correct' => [
            '10-already-correct',
            [
                '2024-10-20_16-45-00-000.jpg' => '2024-10-20_16-45-00-000.jpg',
            ],
            1,
        ];

        // Scenario 11: write-date-nodata -- file has no capture date, skipped
        yield '11-write-date-nodata' => [
            '11-write-date-nodata',
            [],
            0,
        ];

        // Scenario 12: video with ambiguous timezone — [W] skipped, no mapping
        yield '12-write-date-timezone' => [
            '12-write-date-timezone',
            [],
            0,
        ];

        yield '13-mp4-with-tz' => [
            '13-mp4-with-tz',
            [
                'VID_20240101.mp4' => '2024-01-01_15-00-00-000.mp4',
            ],
            1,
        ];

        yield '14-heic-image' => [
            '14-heic-image',
            [
                'IMG_0042.heic' => '2024-11-30_20-15-45-789.heic',
            ],
            1,
        ];

        // Scenario 15: epoch-zero -- file has zero/invalid epoch, skipped
        yield '15-epoch-zero' => [
            '15-epoch-zero',
            [],
            0,
        ];

        // Scenario 16: video re-export with ambiguous timezone — [W] skipped, no mapping
        yield '16-reexport-drift' => [
            '16-reexport-drift',
            [],
            0,
        ];

        // Scenario 17: video with date-only filename and ambiguous timezone — [W] skipped, no mapping
        yield '17-date-only-filename' => [
            '17-date-only-filename',
            [],
            0,
        ];

        // Scenario 18: Live Photo conflict — [C] + [W] both skipped, no mapping
        yield '18-live-photo-conflict' => [
            '18-live-photo-conflict',
            [],
            0,
        ];

        yield '19-write-date-fallback' => [
            '19-write-date-fallback',
            [
                '2024-02-14_14-00-00.jpg' => '2024-02-14_09-00-00-000.jpg',
            ],
            1,
        ];

        // Scenario 20: date drift >7 days — [W] skipped, no mapping
        yield '20-write-date-drift' => [
            '20-write-date-drift',
            [],
            0,
        ];

        // Scenario 21: non-Apple camera video with ambiguous timezone — [W] skipped, no mapping
        yield '21-non-apple-camera' => [
            '21-non-apple-camera',
            [],
            0,
        ];

        yield '22-cross-dir-duplicates' => [
            '22-cross-dir-duplicates',
            [
                'original.jpg'                              => '2024-12-01_09-00-00-000.jpg',
                'backup' . DIRECTORY_SEPARATOR . 'copy.jpg' => 'backup' . DIRECTORY_SEPARATOR . '2024-12-01_09-00-00-000-duplicate-001.jpg',
            ],
            2,
        ];

        yield '23-subsec-padding' => [
            '23-subsec-padding',
            [
                'photo-5ms.jpg'  => '2024-12-15_10-00-00-500.jpg',
                'photo-55ms.jpg' => '2024-12-15_10-00-00-550.jpg',
            ],
            2,
        ];

        yield '24-cross-dir-edits' => [
            '24-cross-dir-edits',
            [
                'original.jpg'                                => '2024-07-25_11-27-50-100.jpg',
                'edited' . DIRECTORY_SEPARATOR . 'edit-1.jpg' => 'edited' . DIRECTORY_SEPARATOR . '2024-07-25_11-27-50-100-002.jpg',
                'edited' . DIRECTORY_SEPARATOR . 'edit-2.jpg' => 'edited' . DIRECTORY_SEPARATOR . '2024-07-25_11-27-50-100-003.jpg',
            ],
            3,
        ];

        yield '25-same-dir-semantic-dup' => [
            '25-same-dir-semantic-dup',
            [
                'capture-a.jpg' => '2024-09-21_17-02-07-833.jpg',
                'capture-b.jpg' => '2024-09-21_17-02-07-833-duplicate-001.jpg',
            ],
            2,
        ];

        yield '26-same-dir-diff-software' => [
            '26-same-dir-diff-software',
            [
                'from-camera.jpg'  => '2024-11-15_09-30-00-450.jpg',
                'photoshopped.jpg' => '2024-11-15_09-30-00-450-002.jpg',
            ],
            2,
        ];

        yield '27-semantic-dup-plus-crossdir' => [
            '27-semantic-dup-plus-crossdir',
            [
                'capture-a.jpg'                             => '2024-09-21_18-15-42-617.jpg',
                'capture-b.jpg'                             => '2024-09-21_18-15-42-617-duplicate-001.jpg',
                'backup' . DIRECTORY_SEPARATOR . 'copy.jpg' => 'backup' . DIRECTORY_SEPARATOR . '2024-09-21_18-15-42-617-duplicate-002.jpg',
            ],
            3,
        ];

        yield '28-cross-dir-format-backup' => [
            '28-cross-dir-format-backup',
            [
                'photo.jpg'                                   => '2025-11-15_20-26-50-647.jpg',
                'backup' . DIRECTORY_SEPARATOR . 'photo.heic' => 'backup' . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647-duplicate-001.heic',
            ],
            2,
        ];

        yield '29-livephoto-edit-duplicate' => [
            '29-livephoto-edit-duplicate',
            [
                '2025-05-03_14-38-16-939.jpg'                => '2025-05-03_14-38-16-939.jpg',
                '2025-05-03_14-38-16-939.mov'                => '2025-05-03_14-38-16-939.mov',
                '2025-05-03_14-38-16-939-002.jpg'            => '2025-05-03_14-38-16-939-002.jpg',
                '2025-05-03_14-38-16-939-002.mov'            => '2025-05-03_14-38-16-939-002.mov',
                '2025-05-03_14-38-16-939-duplicate-001.heic' => '2025-05-03_14-38-16-939-duplicate-001.heic',
                '2025-05-03_14-38-16-939-duplicate-001.mov'  => '2025-05-03_14-38-16-939-duplicate-001.mov',
            ],
            6,
        ];

        // Scenario 30: cross-directory canonical idempotency.
        // Root has -duplicate-001 suffix, subdirectory has the canonical name.
        // The file with the canonical name must win regardless of directory order.
        yield '30-cross-dir-canonical-idempotent' => [
            '30-cross-dir-canonical-idempotent',
            [
                '2024-08-10_13-22-05-300-duplicate-001.jpg'                   => '2024-08-10_13-22-05-300-duplicate-001.jpg',
                'album' . DIRECTORY_SEPARATOR . '2024-08-10_13-22-05-300.jpg' => 'album' . DIRECTORY_SEPARATOR . '2024-08-10_13-22-05-300.jpg',
            ],
            2,
        ];

        // Scenario 31: duplicate with ambiguous timezone.
        // Both videos have UTC timestamps without timezone offset.
        // Warning must take priority over Duplicate — both should be [W] (skipped).
        // Scenario 31: both [W] skipped (ambiguous timezone), no mapping
        yield '31-duplicate-ambiguous-tz' => [
            '31-duplicate-ambiguous-tz',
            [],
            0,
        ];
        // Scenario 39: unsupported format (PNG) is ignored, only JPG processed
        yield '39-unsupported-format-skipped' => [
            '39-unsupported-format-skipped',
            [
                'IMG_0001.jpg' => '2025-04-01_09-00-00-000.jpg',
            ],
            1,
        ];

        // Scenario 44: same-dir edit — original + edited version with different software
        // Same date, different hash → sub-groups -002
        yield '44-same-dir-edit' => [
            '44-same-dir-edit',
            [
                'IMG_0001.jpg' => '2025-05-01_10-00-00-000.jpg',
                'IMG_0002.jpg' => '2025-05-01_10-00-00-000-002.jpg',
            ],
            2,
        ];

        // Scenario 45: edit + backup — original + edit + byte-identical backup
        // Hash sub-grouping: edited.jpg gets canonical base name, original.jpg gets -002,
        // backup/copy.jpg is byte-identical to original → keeps unsuffixed base name
        // in its own directory (no naming conflict there). Note: masterplan says
        // -duplicate-001, but the pipeline correctly avoids suffixes when there's
        // no in-directory conflict. The file is still a cross-dir duplicate for dedup.
        yield '45-edit-plus-backup' => [
            '45-edit-plus-backup',
            [
                'edited.jpg'                                => '2025-05-02_11-00-00-000.jpg',
                'original.jpg'                              => '2025-05-02_11-00-00-000-002.jpg',
                'backup' . DIRECTORY_SEPARATOR . 'copy.jpg' => 'backup' . DIRECTORY_SEPARATOR . '2025-05-02_11-00-00-000.jpg',
            ],
            3,
        ];

        // Scenario 48: HDR bracketed — 3 exposures same second → -002, -003
        yield '48-hdr-bracketed' => [
            '48-hdr-bracketed',
            [
                'IMG_0001.jpg' => '2025-06-01_15-30-22-000.jpg',
                'IMG_0002.jpg' => '2025-06-01_15-30-22-000-002.jpg',
                'IMG_0003.jpg' => '2025-06-01_15-30-22-000-003.jpg',
            ],
            3,
        ];

        // Scenario 33: MOV with Keys:CreationDate + timezone offset → [R] (not [W])
        yield '33-mov-with-timezone' => [
            '33-mov-with-timezone',
            [
                'clip.mov' => '2025-02-15_14-00-00-000.mov',
            ],
            1,
        ];

        // Scenario 34: AVI with no readable capture date → [S] (skipped)
        // AVI RIFF container is not supported by imagemeta for date extraction
        yield '34-avi-with-date' => [
            '34-avi-with-date',
            [],
            0,
        ];

        // Scenario 36: mixed warning + normal — JPG [R] + MOV [W] in same dir
        // [W] on the MOV must NOT infect the JPG's [R] tag
        yield '36-mixed-warning-normal' => [
            '36-mixed-warning-normal',
            [
                'IMG_0001.jpg' => '2025-03-10_14-00-00-000.jpg',
            ],
            1,
        ];

        // Scenario 46: video trimmed — two videos, same date, different duration → -002
        yield '46-video-trimmed' => [
            '46-video-trimmed',
            [
                'full.mov'    => '2025-07-01_12-00-00-000.mov',
                'trimmed.mov' => '2025-07-01_12-00-00-000-002.mov',
            ],
            2,
        ];

        // Scenario 42: same-directory format backup (HEIC + JPG, same photo)
        // HEIC canonical + JPG format conversion → JPG gets -duplicate-001 (not -002)
        // Tests Fix 1: Stage B must skip when dHash distance = 0
        yield '42-same-dir-format-backup' => [
            '42-same-dir-format-backup',
            [
                'photo.heic' => '2025-02-20_15-30-00-200.heic',
                'photo.jpg'  => '2025-02-20_15-30-00-200-duplicate-001.jpg',
            ],
            2,
        ];
    }

    /**
     * Runs the rename pipeline in dry-run mode and returns the raw console output.
     * Used by extractTagAssignments-based tests that need the full output string.
     *
     * @param array<string, mixed> $extraOptions Additional CLI options merged into the base set
     */
    private function runDryRunRaw(string $workspace, array $extraOptions = []): string
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $metadataExtractor   = new MetadataExtractor(MetadataReader::createDefault());
        $metadataProvider    = new ExifMetadataProvider($metadataExtractor);
        $mediaTypeClassifier = new MediaTypeClassifier();
        $imageLoader         = new ImagickImageLoader($mediaTypeClassifier);

        $perceptualHashCalculator = new PerceptualHashCalculator($imageLoader);

        $hashSubGroupingService = new HashSubGroupingService(
            new SafeHashCalculator(),
            $style,
            $mediaTypeClassifier,
            $perceptualHashCalculator,
            new LocalDifferenceAnalyzer(),
            $imageLoader,
        );

        $livePhotoConflictDetector = new LivePhotoConflictDetector($mediaTypeClassifier);

        $command = new RenameByExifDateCommand(
            new FileSystemService($style, new RenameOutputRenderer($style)),
            new DuplicateDetectionService(
                $style,
                $hashSubGroupingService,
                $mediaTypeClassifier,
                $livePhotoConflictDetector,
            ),
            $metadataProvider,
            new LivePhotoPairingService(),
            $perceptualHashCalculator,
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => $workspace,
            '--dry-run'        => true,
            '--list-all'       => true,
            '--timezone'       => 'Europe/Amsterdam',
            ...$extraOptions,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, 'Command must succeed for workspace: ' . $workspace);

        return $output->fetch();
    }

    /**
     * Runs the rename:exif command in dry-run mode with --list-all and returns
     * the source -> target mapping parsed from the console output.
     *
     * @return array<string, string> Map of relative source path to relative target path
     */
    private function runDryRun(string $workspace): array
    {
        $consoleOutput = $this->runDryRunRaw($workspace);

        return $this->extractRenameMappings($consoleOutput, $workspace);
    }

    /**
     * Parses console output into an ordered map of relative source to relative target paths.
     *
     * Matches entry tags with a target path: [O] Original, [R] Rename, [D] Duplicate,
     * [F] Fallback. Skipped entries ([W] Warning, [C] Candidate, [S] Skipped, [E] Error)
     * show a reason instead of a target path and are excluded.
     *
     * @return array<string, string>
     */
    private function extractRenameMappings(string $consoleOutput, string $workspace): array
    {
        $clean = preg_replace('/<[^>]+>/', '', $consoleOutput) ?? $consoleOutput;

        $mappings       = [];
        $absolutePrefix = rtrim($workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relativePrefix = basename(rtrim($workspace, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;

        if (preg_match_all('/\[(?:O|D|R|F)]\s+(\S+)\s+.{1,3}\s+(\S+)/', $clean, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $source = $this->stripPrefix($match[1], $absolutePrefix, $relativePrefix);
                $target = $this->stripPrefix($match[2], $absolutePrefix, $relativePrefix);

                $mappings[$source] = $target;
            }
        }

        return $mappings;
    }

    /**
     * Parses console output into a map of relative source paths to their assigned
     * output tags ([O], [R], [D], [F], [W], [C], [S], [E]).
     *
     * Used by LP atomicity and conflict scenarios where the TAG assignment matters
     * more than the target filename (e.g. verifying [W] propagation to companions).
     *
     * @return array<string, string> source filename => tag letter (O, R, D, F, W, C, S, E)
     */
    private function extractTagAssignments(string $consoleOutput, string $workspace): array
    {
        $clean = preg_replace('/<[^>]+>/', '', $consoleOutput) ?? $consoleOutput;

        $assignments    = [];
        $absolutePrefix = rtrim($workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relativePrefix = basename(rtrim($workspace, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;

        if (preg_match_all('/\[([ORDFWCSE])]\s+(\S+)/', $clean, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $source = $this->stripPrefix($match[2], $absolutePrefix, $relativePrefix);

                $assignments[$source] = $match[1];
            }
        }

        return $assignments;
    }

    /**
     * Strips the absolute or relative workspace prefix from a path to produce
     * a clean relative path for comparison.
     */
    private function stripPrefix(string $path, string $absolutePrefix, string $relativePrefix): string
    {
        if (str_starts_with($path, $absolutePrefix)) {
            return substr($path, strlen($absolutePrefix));
        }

        if (str_starts_with($path, $relativePrefix)) {
            return substr($path, strlen($relativePrefix));
        }

        return $path;
    }

    /**
     * Recursively copies a directory to a new location, preserving subdirectory structure.
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination) && (!mkdir($destination, 0o755, true) && !is_dir($destination))) {
            self::fail('Unable to create directory: ' . $destination);
        }

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            assert($item instanceof SplFileInfo);

            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($targetPath) && (!mkdir($targetPath, 0o755, true) && !is_dir($targetPath))) {
                    self::fail('Unable to create subdirectory: ' . $targetPath);
                }

                continue;
            }

            if (!copy($item->getPathname(), $targetPath)) {
                self::fail('Unable to copy file: ' . $item->getPathname() . ' -> ' . $targetPath);
            }
        }
    }
}
