<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use DateTimeZone;
use FilesystemIterator;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataExtractor;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\AssetGroupAdapter;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetector;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_map;
use function array_unique;
use function assert;
use function copy;
use function implode;
use function is_dir;
use function ksort;
use function mkdir;
use function preg_quote;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Runs both the legacy (DuplicateDetectionService) and the new refactored pipeline
 * (CaptureGroupBuilder, SubgroupClassifier, etc.) on the same test fixtures and
 * verifies that they produce identical rename results.
 *
 * This test acts as a high-level regression suite to ensure that the complex
 * logic of the new modular pipeline (which handles Live Photo pairing, collision
 * resolution, and canonical selection) perfectly matches the behavior of the
 * original monolithic service for all supported scenarios.
 */
#[CoversNothing]
final class PipelineDifferentialTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Resolves the absolute path to the committed test image fixtures.
     */
    private function testImagesDir(): string
    {
        return __DIR__ . '/../Fixtures/Images';
    }

    /**
     * Executes both pipelines on a temporary workspace copy of the fixture
     * and asserts that the resulting file-to-target-path maps are equivalent.
     *
     * @param string $scenarioDir Sub-directory within tests/Fixtures/Images to use
     */
    #[Test]
    #[DataProvider('equivalentScenarioProvider')]
    public function pipelinesProduceEquivalentRenamePlans(string $scenarioDir): void
    {
        $sourceDir = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir, 'Scenario directory must exist: ' . $scenarioDir);

        $workspace = $this->createTempWorkspace('diff_test_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $oldResult = $this->runOldPipeline($targetDir);
            $newResult = $this->runNewPipeline($targetDir);

            self::assertSame(
                $oldResult,
                $newResult,
                sprintf(
                    "Pipeline divergence in fixture: %s\nOld: %s\nNew: %s",
                    $scenarioDir,
                    var_export($oldResult, true),
                    var_export($newResult, true),
                ),
            );
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Provides fixture directories where both pipelines must produce identical results.
     *
     * Only includes scenarios without HEIC vs JPG canonical competition (that's an
     * intentional change in the new pipeline) and without LP second-pass pairing
     * (the new pipeline's LP second pass is deferred to Task 2.9).
     *
     * @return iterable<string, array{string}>
     */
    public static function equivalentScenarioProvider(): iterable
    {
        // Single file, basic rename
        yield '01-basic-rename' => ['01-basic-rename'];

        // Two files with identical EXIF date -> duplicate numbering
        yield '02-duplicates' => ['02-duplicates'];

        // Extension normalization (.JPEG -> .jpg)
        yield '09-extension-normalize' => ['09-extension-normalize'];

        // Already correctly named file (idempotent)
        yield '10-already-correct' => ['10-already-correct'];
    }

    /**
     * Runs the old pipeline (DuplicateDetectionService) and returns a normalized rename map.
     *
     * @return array<string, string> Sorted map of relative source path to relative target path
     */
    private function runOldPipeline(string $workspace): array
    {
        $io = $this->createSilentIo();

        $metadataExtractor = new MetadataExtractor(MetadataReader::createDefault());
        $metadataProvider  = new ExifMetadataProvider($metadataExtractor);
        $metadataProvider->setDefaultTimezone(new DateTimeZone('Europe/Berlin'));

        $mediaTypeClassifier = new MediaTypeClassifier();
        $imageLoader         = new ImagickImageLoader($mediaTypeClassifier);

        $perceptualHashCalculator = new PerceptualHashCalculator($imageLoader);

        $hashSubGroupingService = new HashSubGroupingService(
            new SafeHashCalculator(),
            $io,
            $mediaTypeClassifier,
            $perceptualHashCalculator,
            new LocalDifferenceAnalyzer(),
            $imageLoader,
        );

        $livePhotoConflictDetector = new LivePhotoConflictDetector($mediaTypeClassifier);

        $duplicateDetectionService = new DuplicateDetectionService(
            $io,
            $hashSubGroupingService,
            $mediaTypeClassifier,
            $livePhotoConflictDetector,
        );

        $renameStrategy              = new ExifDateFilenameStrategy('Y-m-d_H-i-s-v', $metadataProvider);
        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        $iterator = $this->createFileIterator($workspace);

        // Phase 1: Group files by duplicate identifier
        $fileDuplicateCollection = $duplicateDetectionService->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $workspace,
        );

        // LP second pass (mirrors what the command does)
        $iterator->rewind();

        $livePhotoPairingService = new LivePhotoPairingService();
        $pairings                = $livePhotoPairingService->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $fileDuplicateCollection,
            contentIdentifierResolver: $renameStrategy->getLivePhotoContentIdentifier(...),
        );

        foreach ($pairings as $pairing) {
            $duplicateIdentifier = $pairing->getDuplicateIdentifier();
            $fileDuplicate       = $fileDuplicateCollection->get($duplicateIdentifier);

            if ($fileDuplicate instanceof FileDuplicate) {
                $fileDuplicate->addFile($pairing->getSourceFile());

                continue;
            }

            $fileDuplicate = new FileDuplicate()
                ->addFile($pairing->getSourceFile())
                ->setTarget($pairing->getTargetFile());

            $fileDuplicateCollection->set($duplicateIdentifier, $fileDuplicate);
        }

        // Phase 2: Assign duplicate filenames
        $fileDuplicateCollection = $duplicateDetectionService->createDuplicateFilenames(
            $fileDuplicateCollection,
            $workspace,
            true,  // useFileExtensionFromSource (matches command behavior)
            false, // skipHashSubGrouping
        );

        $duplicateDetectionService->clearHashCache();

        return $this->extractRenameMap($fileDuplicateCollection, $workspace);
    }

    /**
     * Runs the new pipeline chain and returns a normalized rename map.
     *
     * @return array<string, string> Sorted map of relative source path to relative target path
     */
    private function runNewPipeline(string $workspace): array
    {
        $io = $this->createSilentIo();

        $metadataExtractor = new MetadataExtractor(MetadataReader::createDefault());
        $metadataProvider  = new ExifMetadataProvider($metadataExtractor);
        $metadataProvider->setDefaultTimezone(new DateTimeZone('Europe/Berlin'));

        $mediaTypeClassifier = new MediaTypeClassifier();
        $imageLoader         = new ImagickImageLoader($mediaTypeClassifier);

        $perceptualHashCalculator = new PerceptualHashCalculator($imageLoader);

        $hashSubGroupingService = new HashSubGroupingService(
            new SafeHashCalculator(),
            $io,
            $mediaTypeClassifier,
            $perceptualHashCalculator,
            new LocalDifferenceAnalyzer(),
            $imageLoader,
        );

        $livePhotoConflictDetector = new LivePhotoConflictDetector($mediaTypeClassifier);

        $renameStrategy              = new ExifDateFilenameStrategy('Y-m-d_H-i-s-v', $metadataProvider);
        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        // Step 1: CaptureGroupBuilder
        $captureGroupBuilder = new CaptureGroupBuilder(
            $io,
            $mediaTypeClassifier,
            $livePhotoConflictDetector,
            new LivePhotoPairingService(),
        );

        $context  = new PipelineContext($workspace);
        $iterator = $this->createFileIterator($workspace);

        $groups = $captureGroupBuilder->build(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $context,
        );

        // Step 2: SubgroupClassifier
        $subgroupClassifier = new SubgroupClassifier(
            $hashSubGroupingService,
            $mediaTypeClassifier,
            new OrphanLivePhotoVideoReconciler($mediaTypeClassifier, $perceptualHashCalculator, $io),
            $io,
        );
        $subgroupClassifier->classify($groups);

        // Step 3: RoleAssigner (with empty format priority to match old pipeline behavior)
        $canonicalScorer = new CanonicalScorer();
        $canonicalScorer->setFormatPriority([]);
        $canonicalScorer->setSourceDirectory($workspace);

        $mediaCompatibilityPolicy = new MediaCompatibilityPolicy($mediaTypeClassifier);
        $companionDetector        = new CompanionDetector($mediaCompatibilityPolicy);
        $roleAssigner             = new RoleAssigner($canonicalScorer, $companionDetector, $mediaCompatibilityPolicy);
        $roleAssigner->assign($groups, $context);

        // Step 4: TargetNameResolver
        $targetNameResolver = new TargetNameResolver();
        $targetNameResolver->resolve($groups, true); // useFileExtensionFromSource = true

        // Step 5: CollisionResolver
        $collisionResolver = new CollisionResolver();
        $collisionResolver->resolve($groups, $context);

        // Step 6: AssetGroupAdapter -> FileDuplicateCollection
        $adapter                 = new AssetGroupAdapter();
        $fileDuplicateCollection = $adapter->toFileDuplicateCollection($groups);

        return $this->extractRenameMap($fileDuplicateCollection, $workspace);
    }

    /**
     * Extracts a normalized rename map from a FileDuplicateCollection.
     *
     * The map uses relative paths (stripped of the workspace prefix) for
     * stability across temp directories. Entries are sorted by source path.
     *
     * @param FileDuplicateCollection $collection The collection of duplicates.
     * @param string                  $workspace  The base directory for relative paths.
     *
     * @return array<string, string> Sorted map of relative source to relative target.
     */
    private function extractRenameMap(FileDuplicateCollection $collection, string $workspace): array
    {
        $prefix = rtrim($workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $map    = [];

        /** @var FileDuplicate $fileDuplicate */
        foreach ($collection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $sourcePath = $rename->getSource()->getPathname();
                $targetPath = $rename->getTarget()->getPathname();

                $relativeSource = $this->stripPrefix($sourcePath, $prefix);
                $relativeTarget = $this->stripPrefix($targetPath, $prefix);

                $map[$relativeSource] = $relativeTarget;
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * Strips the workspace prefix from a path to produce a relative path.
     */
    private function stripPrefix(string $path, string $prefix): string
    {
        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    /**
     * Creates a buffered SymfonyStyle instance that swallows all output.
     */
    private function createSilentIo(): SymfonyStyle
    {
        return new SymfonyStyle(
            new ArrayInput([]),
            new BufferedOutput(),
        );
    }

    /**
     * Creates a file iterator matching the command's behavior for supported media extensions.
     *
     * @return RecursiveIteratorIterator<RecursiveRegexFileFilterIterator>
     */
    private function createFileIterator(string $directory): RecursiveIteratorIterator
    {
        $fileExtensionRegex = '/\.(' . implode('|', array_map(
            static fn (string $ext): string => $ext === 'jpg' ? 'jpe?g' : preg_quote($ext, '/'),
            array_unique(array_filter(
                Constants::SUPPORTED_MEDIA_EXTENSIONS,
                static fn (string $ext): bool => $ext !== 'jpeg',
            )),
        )) . ')$/i';

        $recursiveIterator = new RecursiveRegexFileFilterIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS,
            ),
            $fileExtensionRegex,
        );

        return new RecursiveIteratorIterator(
            $recursiveIterator,
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
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
