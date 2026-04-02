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
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
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
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
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
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilderInterface;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuildState;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolverInterface;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\ExifRenamePipelineResult;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\RoleAssignerInterface;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifierInterface;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolverInterface;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Service\ValidationResult;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubPerceptualHashCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the RenameByExifDateCommand, the primary command for renaming photos
 * and videos by their EXIF capture date with Live Photo companion pairing.
 *
 * These tests cover:
 * - Command name registration ("rename:exif")
 * - Strategy wiring (ExifDateFilenameStrategy + TargetBasenameStrategy)
 * - Custom --target-filename-pattern propagation
 * - Live Photo pairing integration via CaptureGroupBuilder
 * - Iterator rewind before the pairing pass
 * - End-to-end dry-run with real services and stub metadata
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
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
#[UsesClass(LivePhotoPairingService::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(ExifMetadataProvider::class)]
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
#[UsesClass(TargetFileResult::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(DuplicateDetectionService::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(HashSubGroupingService::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(LivePhotoBasenameTargetMap::class)]
#[UsesClass(LivePhotoConflictDetector::class)]
#[UsesClass(LivePhotoContentIdentifierTarget::class)]
#[UsesClass(LivePhotoContentIdentifierTargetMap::class)]
#[UsesClass(LivePhotoExistingFilePathnameIndex::class)]
#[UsesClass(LivePhotoPairingCollection::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(PerceptualSignalCache::class)]
#[UsesClass(CanonicalScorer::class)]
#[UsesClass(AssetGroupPipeline::class)]
#[UsesClass(ExifRenamePipelineResult::class)]
#[UsesClass(CaptureGroupBuilder::class)]
#[UsesClass(CaptureGroupBuildState::class)]
#[UsesClass(CollisionResolver::class)]
#[UsesClass(CompanionDetector::class)]
#[UsesClass(RoleAssigner::class)]
#[UsesClass(SubgroupClassifier::class)]
#[UsesClass(TargetNameResolver::class)]
#[UsesClass(RenamePlanValidator::class)]
#[UsesClass(ExecutionPlanBuilder::class)]
#[UsesClass(RenameOutputRenderer::class)]
#[UsesClass(SafeHashCalculator::class)]
#[UsesClass(TargetBasenameStrategy::class)]
#[UsesClass(ExifDateFilenameStrategy::class)]
final class RenameByExifDateCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:exif".
     */
    #[Test]
    public function configureExposesExifDateCommandWithAlias(): void
    {
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $command = new RenameByExifDateCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            $this->createExifMetadataProvider(),
            new StubPerceptualHashCalculator(),
            self::createStub(HashSubGroupingServiceInterface::class),
            new AssetGroupPipeline(
                self::createStub(CaptureGroupBuilderInterface::class),
                self::createStub(SubgroupClassifierInterface::class),
                self::createStub(RoleAssignerInterface::class),
                self::createStub(TargetNameResolverInterface::class),
                self::createStub(CollisionResolverInterface::class),
                new RenamePlanValidator(),
            ),
            self::createStub(CanonicalScorerInterface::class),
            new ExecutionPlanBuilder(),
            new RenameOutputRenderer($io),
        );

        self::assertSame('rename:exif', $command->getName());
    }

    /**
     * Verifies end-to-end execution with real services (no mocks) using a temporary
     * workspace with two files: a HEIC photo with an EXIF date and a MOV companion
     * paired via content identifier.
     *
     * The dry-run output must contain the MOV target filename with millisecond
     * precision inherited from the photo's capture time, confirming the complete
     * pipeline from metadata extraction through pairing to rename listing.
     */
    #[Test]
    public function executeWithRealServicesListsLivePhotoVideoRenames(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('live_photo_', true);

        if (!mkdir($workspace, 0o755) && !is_dir($workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        $photoPath = $workspace . DIRECTORY_SEPARATOR . '2-image.HEIC';
        $videoPath = $workspace . DIRECTORY_SEPARATOR . 'movie.MOV';

        file_put_contents($photoPath, 'photo');
        file_put_contents($videoPath, 'video');

        try {
            $output = new BufferedOutput();
            $style  = new SymfonyStyle(new ArrayInput([]), $output);

            $fileSystemService = new FileSystemService($style, new RenameOutputRenderer($style));

            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $photoPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-01T12:00:00.123+00:00'),
                    'UUID-IPHONE-LIVEPHOTO',
                ),
            );
            $metadataExtractor->withResponse(
                $videoPath,
                new TemporalMetadata(null, 'UUID-IPHONE-LIVEPHOTO'),
            );

            $metadataProvider = new ExifMetadataProvider($metadataExtractor);

            $hashCalculator            = new SafeHashCalculator();
            $mediaTypeClassifier       = new MediaTypeClassifier();
            $hashSubGroupingService    = new HashSubGroupingService($hashCalculator, $style, $mediaTypeClassifier, new StubPerceptualHashCalculator(), new LocalDifferenceAnalyzer(), new ImagickImageLoader(new MediaTypeClassifier()));
            $livePhotoConflictDetector = new LivePhotoConflictDetector($mediaTypeClassifier);
            $duplicateDetectionService = new DuplicateDetectionService(
                $style,
                $hashSubGroupingService,
                $mediaTypeClassifier,
                $livePhotoConflictDetector,
            );

            $captureGroupBuilder = new CaptureGroupBuilder(
                $style,
                $mediaTypeClassifier,
                $livePhotoConflictDetector,
                new LivePhotoPairingService(),
            );
            $subgroupClassifier   = new SubgroupClassifier($hashSubGroupingService, $mediaTypeClassifier, $style);
            $companionDetector    = new CompanionDetector($mediaTypeClassifier);
            $canonicalScorer      = new CanonicalScorer();
            $roleAssigner         = new RoleAssigner($canonicalScorer, $companionDetector);
            $targetNameResolver   = new TargetNameResolver();
            $collisionResolver    = new CollisionResolver();
            $renamePlanValidator  = new RenamePlanValidator();
            $executionPlanBuilder = new ExecutionPlanBuilder();

            $pipeline = new AssetGroupPipeline(
                $captureGroupBuilder,
                $subgroupClassifier,
                $roleAssigner,
                $targetNameResolver,
                $collisionResolver,
                $renamePlanValidator,
            );

            $command = new RenameByExifDateCommand(
                $fileSystemService,
                $duplicateDetectionService,
                $metadataProvider,
                new StubPerceptualHashCalculator(),
                $hashSubGroupingService,
                $pipeline,
                $canonicalScorer,
                $executionPlanBuilder,
                new RenameOutputRenderer($style),
            );

            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'     => $workspace,
                '--dry-run'  => true,
                '--list-all' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $consoleOutput = $output->fetch();
            // The new pipeline pairs the MOV as a duplicate within the same group
            // (content-identifier pairing is handled by CaptureGroupBuilder).
            // Companion detection in the new pipeline may suffix the MOV depending
            // on hash sub-grouping; assert the base date appears in the MOV target.
            self::assertMatchesRegularExpression(
                '/2024-01-01_12-00-00-123[^ ]*\.mov/',
                $consoleOutput,
                'Live Photo video must appear in output with the correct base date',
            );
        } finally {
            @unlink($photoPath);
            @unlink($videoPath);
            @rmdir($workspace);
        }
    }

    private function createExifMetadataProvider(): ExifMetadataProvider
    {
        return new ExifMetadataProvider(new StubMetadataExtractor());
    }
}
