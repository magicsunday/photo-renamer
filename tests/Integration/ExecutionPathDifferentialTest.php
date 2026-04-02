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
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\AssetGroupAdapter;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetector;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\ExifRenamePipelineResult;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\RenamePlanValidator;
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
use function count;
use function implode;
use function is_dir;
use function mkdir;
use function preg_quote;
use function rtrim;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Differential integration test comparing the OLD execution path
 * (AssetGroupAdapter -> FileDuplicateCollection -> buildOutputEntries)
 * against the NEW execution path (ExecutionPlanBuilder -> buildOutputEntriesFromPlan).
 *
 * Both paths consume the same AssetGroupPipeline result. Any divergence in
 * the generated output entries indicates a rendering parity regression.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class ExecutionPathDifferentialTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Returns the absolute path to the committed test-images directory.
     */
    private function testImagesDir(): string
    {
        return __DIR__ . '/../Fixtures/Images';
    }

    /**
     * Executes both the legacy and the new execution paths on the same set of
     * assets and verifies that they produce identical output entries.
     *
     * This test is critical for ensuring that the migration from the
     * `FileDuplicateCollection` based rendering to the `ExecutionPlan` based
     * rendering does not change the behavior or naming of any files.
     *
     * @param string $scenarioDir The subdirectory name within the test images fixture.
     */
    #[Test]
    #[DataProvider('parityScenarioProvider')]
    public function executionPathsProduceParityOutputEntries(string $scenarioDir): void
    {
        $sourceDir = $this->testImagesDir() . DIRECTORY_SEPARATOR . $scenarioDir;

        self::assertDirectoryExists($sourceDir, 'Scenario directory must exist: ' . $scenarioDir);

        $workspace = $this->createTempWorkspace('exec_diff_');
        $targetDir = $workspace . DIRECTORY_SEPARATOR . $scenarioDir;

        try {
            $this->copyDirectory($sourceDir, $targetDir);

            $pipelineResult = $this->runPipeline($targetDir);

            $options = new RenameOptions(
                sourceBaseDirectory: $targetDir,
            );

            $result = new RenameResult(
                fallbackDateFiles: $pipelineResult->context->getFallbackDateFiles(),
                ambiguousTimezoneFiles: $pipelineResult->context->getAmbiguousTimezoneFiles(),
                livePhotoConflictFiles: $pipelineResult->context->getLivePhotoConflictFiles(),
            );

            // OLD path: AssetGroupAdapter -> FileDuplicateCollection -> buildOutputEntries
            $adapter                 = new AssetGroupAdapter();
            $fileDuplicateCollection = $adapter->toFileDuplicateCollection($pipelineResult->groups);
            $renderer                = $this->createRenderer();

            $sourceBaseDirectory = rtrim($targetDir, DIRECTORY_SEPARATOR);

            [$oldEntries] = $renderer->buildOutputEntries(
                $fileDuplicateCollection,
                $options,
                $result,
                $sourceBaseDirectory,
            );

            // NEW path: ExecutionPlanBuilder -> buildOutputEntriesFromPlan
            $builder       = new ExecutionPlanBuilder();
            $executionPlan = $builder->build(
                $pipelineResult->groups,
                $pipelineResult->context,
            );

            $newRenderer  = $this->createRenderer();
            [$newEntries] = $newRenderer->buildOutputEntriesFromPlan(
                $executionPlan,
                $options,
                $result,
                $sourceBaseDirectory,
            );

            // Compare entry count
            self::assertCount(
                count($oldEntries),
                $newEntries,
                sprintf(
                    'Entry count mismatch in fixture %s: old=%d, new=%d',
                    $scenarioDir,
                    count($oldEntries),
                    count($newEntries),
                ),
            );

            // Compare individual entries
            foreach ($oldEntries as $index => $oldEntry) {
                $newEntry = $newEntries[$index];
                $context  = sprintf('fixture=%s, index=%d', $scenarioDir, $index);

                // Same source path
                self::assertSame(
                    $oldEntry->sourcePath,
                    $newEntry->sourcePath,
                    sprintf('sourcePath mismatch (%s)', $context),
                );

                // Same target path
                self::assertSame(
                    $oldEntry->targetPath,
                    $newEntry->targetPath,
                    sprintf('targetPath mismatch (%s)', $context),
                );

                // Same tag
                self::assertSame(
                    $oldEntry->tag,
                    $newEntry->tag,
                    sprintf(
                        'tag mismatch (%s): old=%s, new=%s',
                        $context,
                        $oldEntry->tag->value,
                        $newEntry->tag->value,
                    ),
                );

                // Same shouldSkip flag
                self::assertSame(
                    $oldEntry->shouldSkip,
                    $newEntry->shouldSkip,
                    sprintf('shouldSkip mismatch (%s)', $context),
                );

                // Same isDuplicateTarget flag
                self::assertSame(
                    $oldEntry->isDuplicateTarget,
                    $newEntry->isDuplicateTarget,
                    sprintf('isDuplicateTarget mismatch (%s)', $context),
                );
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Provides fixture directories where both execution paths must produce identical output entries.
     *
     * @return iterable<string, array{string}>
     */
    public static function parityScenarioProvider(): iterable
    {
        yield '01-basic-rename' => ['01-basic-rename'];
        yield '02-duplicates' => ['02-duplicates'];
        yield '09-extension-normalize' => ['09-extension-normalize'];
        yield '10-already-correct' => ['10-already-correct'];
    }

    /**
     * Executes the full AssetGroupPipeline for a specific workspace and returns the result.
     *
     * This method bootstraps all required services (metadata extraction, perceptual hashing,
     * subgrouping, etc.) to mirror the real application logic.
     *
     * @param string $workspace Absolute path to the temporary test workspace
     *
     * @return ExifRenamePipelineResult The result of the pipeline execution
     */
    private function runPipeline(string $workspace): ExifRenamePipelineResult
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

        // Build pipeline services
        $captureGroupBuilder = new CaptureGroupBuilder(
            $io,
            $mediaTypeClassifier,
            $livePhotoConflictDetector,
            new LivePhotoPairingService(),
        );

        $subgroupClassifier = new SubgroupClassifier($hashSubGroupingService, $mediaTypeClassifier, $io);

        $canonicalScorer = new CanonicalScorer();
        $canonicalScorer->setFormatPriority([]);
        $canonicalScorer->setSourceDirectory($workspace);

        $companionDetector = new CompanionDetector($mediaTypeClassifier);
        $roleAssigner      = new RoleAssigner($canonicalScorer, $companionDetector);

        $targetNameResolver = new TargetNameResolver();
        $collisionResolver  = new CollisionResolver();

        $renamePlanValidator = new RenamePlanValidator();

        $pipeline = new AssetGroupPipeline(
            $captureGroupBuilder,
            $subgroupClassifier,
            $roleAssigner,
            $targetNameResolver,
            $collisionResolver,
            $renamePlanValidator,
        );

        $iterator = $this->createFileIterator($workspace);

        return $pipeline->run(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $workspace,
            true, // useFileExtensionFromSource
        );
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
     * Creates a RenameOutputRenderer with a silent IO.
     */
    private function createRenderer(): RenameOutputRenderer
    {
        return new RenameOutputRenderer($this->createSilentIo());
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
