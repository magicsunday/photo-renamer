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
use MagicSunday\Renamer\Command\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
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
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const PREG_SET_ORDER;

/**
 * Integration test validating all 28 test-image scenarios against their expected
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
     * Provides all 28 test-image scenarios with their expected rename outcomes.
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

        yield '06-ambiguous-timezone' => [
            '06-ambiguous-timezone',
            [
                'MVI_1234.mov' => '2024-02-14_20-30-00-000.mov',
            ],
            1,
        ];

        // Scenario 07: file has no metadata, skipped entirely (not in rename mapping)
        yield '07-no-metadata' => [
            '07-no-metadata',
            [],
            0,
        ];

        yield '08-date-drift' => [
            '08-date-drift',
            [
                '2024-01-15_photo.jpg' => '2024-03-20_10-00-00-000.jpg',
            ],
            1,
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

        yield '12-write-date-timezone' => [
            '12-write-date-timezone',
            [
                '2024-04-20-video.mov' => '2024-04-20_17-45-00-000.mov',
            ],
            1,
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

        yield '16-reexport-drift' => [
            '16-reexport-drift',
            [
                '2022-12-10_14-19-08.mov' => '2024-09-19_02-06-04-000.mov',
            ],
            1,
        ];

        yield '17-date-only-filename' => [
            '17-date-only-filename',
            [
                '2020-02-07-3483.mov' => '2020-02-07_22-20-25-000.mov',
            ],
            1,
        ];

        yield '18-live-photo-conflict' => [
            '18-live-photo-conflict',
            [
                '2024-08-19_11-09-34-857.jpg' => '2020-08-19_11-09-34-857.jpg',
                '2024-08-19_11-09-34-857.mov' => '2020-08-19_11-09-34-000.mov',
            ],
            2,
        ];

        yield '19-write-date-fallback' => [
            '19-write-date-fallback',
            [
                '2024-02-14_14-00-00.jpg' => '2024-02-14_09-00-00-000.jpg',
            ],
            1,
        ];

        yield '20-write-date-drift' => [
            '20-write-date-drift',
            [
                '2024-01-15_10-00-00.jpg' => '2024-06-20_10-00-00-000.jpg',
            ],
            1,
        ];

        yield '21-non-apple-camera' => [
            '21-non-apple-camera',
            [
                'MVI_0511.mov' => '2024-09-15_16-30-00-000.mov',
            ],
            1,
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
    }

    /**
     * Runs the rename:exif command in dry-run mode with --list-all and returns
     * the source -> target mapping parsed from the console output.
     *
     * Uses real MetadataExtractor (imagemeta), real PerceptualHashCalculator (Imagick),
     * and the full service stack to exercise the complete pipeline.
     *
     * @return array<string, string> Map of relative source path to relative target path
     */
    private function runDryRun(string $workspace): array
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $metadataExtractor   = new MetadataExtractor(MetadataReader::createDefault());
        $metadataProvider    = new ExifMetadataProvider($metadataExtractor);
        $mediaTypeClassifier = new MediaTypeClassifier();
        $imageLoader         = new ImagickImageLoader(new MediaTypeClassifier());

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
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, 'Command must succeed for workspace: ' . $workspace);

        $consoleOutput = $output->fetch();

        return $this->extractRenameMappings($consoleOutput, $workspace);
    }

    /**
     * Parses console output into an ordered map of relative source to relative target paths.
     *
     * Matches all rename entry tags: [O] Original, [R] Rename, [D] Duplicate,
     * [W] Warning, [F] Fallback, [C] Candidate. Skipped [S] and error [E] entries
     * use a different output format and are excluded.
     *
     * @return array<string, string>
     */
    private function extractRenameMappings(string $consoleOutput, string $workspace): array
    {
        $clean = preg_replace('/<[^>]+>/', '', $consoleOutput) ?? $consoleOutput;

        $mappings       = [];
        $absolutePrefix = rtrim($workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relativePrefix = basename(rtrim($workspace, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;

        if (preg_match_all('/\[(?:O|D|R|W|F|C)]\s+(\S+)\s+.{1,3}\s+(\S+)/', $clean, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $source = $this->stripPrefix($match[1], $absolutePrefix, $relativePrefix);
                $target = $this->stripPrefix($match[2], $absolutePrefix, $relativePrefix);

                $mappings[$source] = $target;
            }
        }

        return $mappings;
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
