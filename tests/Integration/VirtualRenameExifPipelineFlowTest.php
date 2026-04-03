<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use DateTimeImmutable;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Pipeline\VideoFingerprintMatch;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoDuplicateReconciler;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\PipelineReviewMapper;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcherInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\OutputRendererFactory;
use MagicSunday\Renamer\Test\Fixtures\VirtualFlow\FlatSplFileInfoRecursiveIterator;
use MagicSunday\Renamer\Test\Fixtures\VirtualFlow\StubMetadataAwareLivePhotoRenameStrategy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * Verifies a full virtual `rename:exif` semantic flow without real file operations.
 *
 * The test deliberately wires the real AssetGroup pipeline, real execution-plan
 * projection, real review mapping, and real output projection together while
 * stopping at the filesystem boundary. This gives Wave 2 a fast regression harness
 * for role assignment, naming, warnings, fallback handling, duplicate rendering,
 * review entries, and skipped-file projection without depending on a temp workspace
 * or a real photo corpus.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class VirtualRenameExifPipelineFlowTest extends TestCase
{
    /**
     * Verifies that the virtual `rename:exif` harness exercises the real semantic flow:
     *
     * - Live Photo companion detection
     * - duplicate target suffixing
     * - fallback and ambiguous-timezone quality projection
     * - cross-group video review mapping
     * - skipped file projection
     * - execution-plan and output-entry generation
     *
     * The scenario uses only virtual pathnames plus test doubles at the hash and
     * stream-fingerprint boundaries, so failures surface quickly and deterministically.
     */
    #[Test]
    public function virtualPipelineFlowProjectsExpectedSemanticDecisions(): void
    {
        $sourceDirectory = '/virtual/source';
        $files           = $this->createVirtualFiles($sourceDirectory);
        $strategy        = $this->createStrategy($files);
        $pipeline        = $this->createPipeline($sourceDirectory, $files['reviewA']->getPathname(), $files['reviewB']->getPathname());

        $pipelineResult = $pipeline->run(
            $this->createFileIterator($files),
            $strategy,
            new TargetBasenameStrategy(),
            $sourceDirectory,
            true,
        );

        self::assertTrue($pipelineResult->validationResult->isValid());
        self::assertCount(5, $pipelineResult->groups);
        self::assertCount(1, $pipelineResult->context->getSkippedFiles());
        self::assertCount(1, $pipelineResult->context->getVideoDuplicateCandidates());

        $executionPlan = new ExecutionPlanBuilder()->build(
            $pipelineResult->groups,
            $pipelineResult->context,
        );
        $renameResult = $pipelineResult->context->toRenameResult(
            new PipelineReviewMapper()->mapVideoDuplicateCandidates(
                $pipelineResult->context->getVideoDuplicateCandidates(),
                $sourceDirectory,
            ),
            count($pipelineResult->context->getVideoDuplicateCandidates()),
        );

        self::assertSame(8, $renameResult->scannedFiles);
        self::assertSame(1, $renameResult->crossGroupVideoReviewCount);

        $entries = $this->createRenderer()->buildOutputEntriesFromPlan(
            $executionPlan,
            new RenameOptions(
                dryRun: true,
                listAll: true,
                sourceBaseDirectory: $sourceDirectory,
            ),
            $renameResult,
            $sourceDirectory,
        )->entries;

        $renameEntries = array_values(array_filter(
            $entries,
            static fn (OutputEntry $entry): bool => $entry->isRename(),
        ));
        $infoEntries = array_values(array_filter(
            $entries,
            static fn (OutputEntry $entry): bool => $entry->isInfo(),
        ));
        $skipEntries = array_values(array_filter(
            $entries,
            static fn (OutputEntry $entry): bool => $entry->isSkip(),
        ));

        $canonicalEntry = $this->findRenameEntry($renameEntries, 'main/2024-01-01_10-00-00-123.HEIC');
        self::assertSame(OutputEntryTag::Rename, $canonicalEntry->tag);
        self::assertSame('main/2024-01-01_10-00-00-123.heic', $canonicalEntry->targetPath);

        $companionEntry = $this->findRenameEntry($renameEntries, 'main/IMG_0001.MOV');
        self::assertSame(OutputEntryTag::Rename, $companionEntry->tag);
        self::assertSame('main/2024-01-01_10-00-00-123.mov', $companionEntry->targetPath);

        $duplicateEntry = $this->findRenameEntry($renameEntries, 'main/IMG_0001-copy.HEIC');
        self::assertSame(OutputEntryTag::Duplicate, $duplicateEntry->tag);
        self::assertSame('main/2024-01-01_10-00-00-123-duplicate-001.heic', $duplicateEntry->targetPath);

        self::assertSame(
            OutputEntryTag::Fallback,
            $this->findRenameEntry($renameEntries, 'main/IMG_fallback.JPG')->tag,
        );
        self::assertSame(
            OutputEntryTag::Warning,
            $this->findRenameEntry($renameEntries, 'main/IMG_warn.MOV')->tag,
        );

        self::assertCount(1, $skipEntries);
        self::assertSame(OutputEntryTag::Skipped, $skipEntries[0]->tag);
        self::assertSame('main/IMG_skipped.JPG', $skipEntries[0]->sourcePath);
        self::assertSame('No capture date', $skipEntries[0]->reason);

        $duplicateInfoEntries = array_values(array_filter(
            $infoEntries,
            static fn (OutputEntry $entry): bool => $entry->tag === OutputEntryTag::Duplicate,
        ));
        self::assertCount(1, $duplicateInfoEntries);
        self::assertSame(
            'Duplicate of main/2024-01-01_10-00-00-123.heic',
            $duplicateInfoEntries[0]->reason,
        );

        $reviewEntries = array_values(array_filter(
            $infoEntries,
            static fn (OutputEntry $entry): bool => $entry->tag === OutputEntryTag::Review,
        ));
        self::assertCount(1, $reviewEntries);
        self::assertSame('alt/review-a.MOV', $reviewEntries[0]->sourcePath);
        self::assertSame(
            'Cross-group video review: archive/review-b.MOV — video stream identical, audio differs',
            $reviewEntries[0]->reason,
        );
    }

    /**
     * Creates the virtual file list consumed by the recursive iterator.
     *
     * The scenario intentionally mixes:
     * - an already-correct canonical still
     * - a Live Photo companion video
     * - a same-group duplicate still
     * - fallback and ambiguous-timezone files
     * - a cross-group review-only video pair
     * - one skipped file with no capture date
     *
     * @param string $sourceDirectory Virtual source root used by the pipeline
     *
     * @return array<string, SplFileInfo> Named virtual source files
     */
    private function createVirtualFiles(string $sourceDirectory): array
    {
        return [
            'canonicalStill' => new SplFileInfo($sourceDirectory . '/main/2024-01-01_10-00-00-123.HEIC'),
            'companionVideo' => new SplFileInfo($sourceDirectory . '/main/IMG_0001.MOV'),
            'duplicateStill' => new SplFileInfo($sourceDirectory . '/main/IMG_0001-copy.HEIC'),
            'fallbackStill'  => new SplFileInfo($sourceDirectory . '/main/IMG_fallback.JPG'),
            'warningVideo'   => new SplFileInfo($sourceDirectory . '/main/IMG_warn.MOV'),
            'reviewA'        => new SplFileInfo($sourceDirectory . '/alt/review-a.MOV'),
            'reviewB'        => new SplFileInfo($sourceDirectory . '/archive/review-b.MOV'),
            'skippedStill'   => new SplFileInfo($sourceDirectory . '/main/IMG_skipped.JPG'),
        ];
    }

    /**
     * Creates a flat recursive iterator over virtual files.
     *
     * The production pipeline expects a RecursiveIteratorIterator because the real
     * command walks a directory tree. The virtual harness only needs one flat list
     * of SplFileInfo objects, so this wrapper provides a leaf-only recursive view
     * without introducing real directories or workspace files.
     *
     * @param array<string, SplFileInfo> $files Named virtual source files
     *
     * @return RecursiveIteratorIterator<FlatSplFileInfoRecursiveIterator> Flat recursive iterator over the virtual file list
     */
    private function createFileIterator(array $files): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new FlatSplFileInfoRecursiveIterator($files)
        );
    }

    /**
     * Creates the in-memory rename strategy backing the virtual scenario.
     *
     * @param array<string, SplFileInfo> $files Named virtual source files
     */
    private function createStrategy(array $files): StubMetadataAwareLivePhotoRenameStrategy
    {
        return new StubMetadataAwareLivePhotoRenameStrategy()
            ->withFile(
                $files['canonicalStill']->getPathname(),
                '2024-01-01_10-00-00-123.HEIC',
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-01T10:00:00.123+00:00'),
                    'lp-1',
                ),
            )
            ->withFile(
                $files['companionVideo']->getPathname(),
                '2024-01-01_10-00-00-123.MOV',
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-01T10:00:00.123+00:00'),
                    'lp-1',
                    false,
                    false,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    2.0,
                ),
            )
            ->withFile(
                $files['duplicateStill']->getPathname(),
                '2024-01-01_10-00-00-123.HEIC',
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-01T10:00:00.123+00:00'),
                    null,
                ),
            )
            ->withFile(
                $files['fallbackStill']->getPathname(),
                '2024-01-02_09-30-00-000.JPG',
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-02T09:30:00.000+00:00'),
                    null,
                    true,
                ),
                false,
            )
            ->withFile(
                $files['warningVideo']->getPathname(),
                '2024-01-03_08-00-00-000.MOV',
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-03T08:00:00.000+00:00'),
                    null,
                    false,
                    true,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    3.0,
                ),
                false,
            )
            ->withFile(
                $files['reviewA']->getPathname(),
                '2024-01-04_07-00-00-000.MOV',
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-04T07:00:00.000+00:00'),
                    null,
                    false,
                    false,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    5.0,
                ),
            )
            ->withFile(
                $files['reviewB']->getPathname(),
                '2024-01-04_07-00-01-000.MOV',
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-04T07:00:01.000+00:00'),
                    null,
                    false,
                    false,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    5.0,
                ),
            )
            ->withFile(
                $files['skippedStill']->getPathname(),
                null,
                null,
                false,
            );
    }

    /**
     * Creates the real pipeline wired with no-op hash grouping and a scripted video matcher.
     *
     * The setup keeps Wave 2 focused on semantic end-to-end coverage: real grouping,
     * real role assignment, real naming, real collision resolution, and real review
     * mapping, while the expensive hash and stream boundaries stay deterministic.
     *
     * @param string $sourceDirectory Virtual source root used by CanonicalScorer
     * @param string $reviewLeftPath  First video of the review-only pair
     * @param string $reviewRightPath Second video of the review-only pair
     */
    private function createPipeline(
        string $sourceDirectory,
        string $reviewLeftPath,
        string $reviewRightPath,
    ): AssetGroupPipeline {
        $progressReporter          = new NullProgressReporter();
        $mediaTypeClassifier       = new MediaTypeClassifier();
        $mediaCompatibilityPolicy  = new MediaCompatibilityPolicy($mediaTypeClassifier);
        $hashSubGroupingService    = self::createStub(HashSubGroupingServiceInterface::class);
        $perceptualHashCalculator  = self::createStub(PerceptualHashCalculatorInterface::class);
        $videoFingerprintMatcher   = self::createStub(VideoStreamFingerprintMatcherInterface::class);
        $canonicalScorer           = new CanonicalScorer();
        $orphanVideoReconciler     = new OrphanLivePhotoVideoReconciler($mediaTypeClassifier, $perceptualHashCalculator, $progressReporter);
        $subgroupClassifier        = new SubgroupClassifier($hashSubGroupingService, $mediaTypeClassifier, $orphanVideoReconciler, $progressReporter);
        $captureGroupBuilder       = new CaptureGroupBuilder($progressReporter, $mediaTypeClassifier);
        $companionDetector         = new CompanionDetector($mediaCompatibilityPolicy);
        $roleAssigner              = new RoleAssigner($canonicalScorer, $companionDetector, $mediaCompatibilityPolicy);
        $crossGroupVideoReconciler = new CrossGroupVideoDuplicateReconciler($mediaCompatibilityPolicy, $videoFingerprintMatcher, $progressReporter);

        $hashSubGroupingService
            ->method('apply')
            ->willReturn(null);

        $videoFingerprintMatcher
            ->method('match')
            ->willReturnCallback(static function (SplFileInfo $leftFile, SplFileInfo $rightFile) use ($reviewLeftPath, $reviewRightPath): VideoFingerprintMatch {
                $leftPath  = $leftFile->getPathname();
                $rightPath = $rightFile->getPathname();

                if (
                    (($leftPath === $reviewLeftPath) && ($rightPath === $reviewRightPath))
                    || (($leftPath === $reviewRightPath) && ($rightPath === $reviewLeftPath))
                ) {
                    return new VideoFingerprintMatch(
                        true,
                        false,
                        false,
                        false,
                        true,
                        'video stream identical, audio differs',
                    );
                }

                return new VideoFingerprintMatch(false, false, false, false, false);
            });

        $canonicalScorer->setFormatPriority(['heic', 'jpg', 'mov']);
        $canonicalScorer->setSourceDirectory($sourceDirectory);

        return new AssetGroupPipeline(
            $captureGroupBuilder,
            $subgroupClassifier,
            $roleAssigner,
            new TargetNameResolver(),
            new CollisionResolver(),
            new RenamePlanValidator(),
            $crossGroupVideoReconciler,
        );
    }

    /**
     * Creates a real renderer backed by buffered console output.
     *
     * The virtual flow only needs the projection behavior, not visible terminal
     * assertions, but using the real renderer keeps the output boundary honest.
     */
    private function createRenderer(): RenameOutputRenderer
    {
        return OutputRendererFactory::create(
            new SymfonyStyle(new ArrayInput([]), new BufferedOutput()),
        );
    }

    /**
     * Returns the single rename entry for the given relative source pathname.
     *
     * @param list<OutputEntry> $entries            Rename entries already filtered by type
     * @param string            $relativeSourcePath Relative source pathname under the virtual source root
     */
    private function findRenameEntry(array $entries, string $relativeSourcePath): OutputEntry
    {
        foreach ($entries as $entry) {
            if ($entry->sourcePath === $relativeSourcePath) {
                return $entry;
            }
        }

        self::fail(sprintf(
            'Missing rename entry for %s. Present rename entries: %s',
            $relativeSourcePath,
            implode(', ', array_map(static fn (OutputEntry $entry): string => $entry->sourcePath, $entries)),
        ));
    }
}
