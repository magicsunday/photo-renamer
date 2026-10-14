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
use LogicException;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Pipeline\VideoFingerprintMatch;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\ContentClassifierInterface;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilderInterface;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoDuplicateReconciler;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcherInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\TargetNameResolverFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Verifies run-scope cleanup and observable results across real pipeline stages.
 *
 * Source-building and external content/fingerprint boundaries remain controlled;
 * real regrouping, classification, role assignment, naming and plan projection
 * establish the behavior without pinning internal call sequences.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class AssetGroupPipelineTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A builder failure propagates as a logic error while the workflow still
     * detaches its cache and restores grouping defaults. Setup uses explicit
     * format/source values, and cleanup performs no classification/source I/O.
     *
     * @return void
     */
    #[Test]
    public function builderFailureStillClosesAnalysisConfigurationScope(): void
    {
        $builder = self::createStub(CaptureGroupBuilderInterface::class);
        $builder->method('build')->willThrowException(new LogicException('synthetic builder failure'));
        $grouping = self::createMock(ContentClassifierInterface::class);
        $selected = [];
        $grouping->expects(self::exactly(2))->method('setMaxMergeRmse')->willReturnCallback(static function (?float $threshold) use (&$selected): void {
            $selected[] = $threshold;
        });
        $grouping->expects(self::never())->method('clearCache');
        $visual = self::createMock(PerceptualHashCalculatorInterface::class);
        $visual->expects(self::exactly(2))->method('setSignalCache')->with(null);
        $scorer = self::createMock(CanonicalScorerInterface::class);
        $scorer->expects(self::once())->method('setFormatPriority')->with(['jpg', 'heic']);
        $scorer->expects(self::once())->method('setSourceDirectory')->with('/synthetic');
        $mediaTypeClassifier = new MediaTypeClassifier();
        $reporter            = new NullProgressReporter();
        $pipeline            = new AssetGroupPipeline(
            $builder,
            new SubgroupClassifier($grouping, $mediaTypeClassifier, new OrphanLivePhotoVideoReconciler($mediaTypeClassifier, $visual, $reporter), $reporter),
            new RoleAssigner($scorer, new CompanionDetector($mediaTypeClassifier), $mediaTypeClassifier),
            TargetNameResolverFactory::create(),
            new CollisionResolver(),
            new RenamePlanValidator(),
            $grouping,
            $visual,
            $scorer,
        );
        $this->expectException(LogicException::class);

        try {
            $pipeline->run(new RecursiveIteratorIterator(new RecursiveArrayIterator([])), new InheritFilenameStrategy(), new TargetBasenameStrategy(), '/synthetic', mergeThreshold: 0.03, formatPriority: ['jpg', 'heic']);
        } finally {
            self::assertSame([0.03, null], $selected);
        }
    }

    /**
     * Two duplicate copies with different date metadata enter one analysis group.
     * A fingerprint stub represents identical video and audio streams; native
     * matching is covered separately. A content-analysis failure must block both
     * merged members in the final plan while preserving their actual bytes.
     * Reversing the phases would classify only singleton groups and miss this.
     *
     * @return void
     */
    #[Test]
    public function duplicateVideoCopiesAreClassifiedAndBlockedAsOneGroup(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $groups     = new AssetGroupCollection();
        $sources    = [];

        try {
            foreach ([1, 2] as $day) {
                $path = $workspace . '/copy-' . $day . '.mov';
                $filesystem->dumpFile($path, 'synthetic duplicate video');
                $sources[] = $path;
                $group     = new AssetGroup('2025-01-0' . $day . '_10-00-00-000');
                $group->addItem(new AssetItem(new SplFileInfo($path), metadata: new TemporalMetadata(new DateTimeImmutable('2025-01-0' . $day . ' 10:00:00'), null, videoDurationSeconds: 2.17)));
                $groups->set($group->groupKey, $group);
            }

            $builder = self::createStub(CaptureGroupBuilderInterface::class);
            $builder->method('build')->willReturn($groups);
            $matcher = self::createStub(VideoStreamFingerprintMatcherInterface::class);
            $matcher->method('match')->willReturn(new VideoFingerprintMatch(
                videoStreamMatched: true,
                audioStreamMatched: true,
                bothWithoutAudio: false,
                missingAudioOnOneSide: false,
                audioMismatch: false,
            ));
            $grouping = self::createStub(ContentClassifierInterface::class);
            $grouping->method('classify')->willThrowException(new RuntimeException('synthetic classification failure'));
            $visual              = self::createStub(PerceptualHashCalculatorInterface::class);
            $scorer              = new CanonicalScorer();
            $mediaTypeClassifier = new MediaTypeClassifier();
            $reporter            = new NullProgressReporter();
            $pipeline            = new AssetGroupPipeline(
                $builder,
                new SubgroupClassifier($grouping, $mediaTypeClassifier, new OrphanLivePhotoVideoReconciler($mediaTypeClassifier, $visual, $reporter), $reporter),
                new RoleAssigner($scorer, new CompanionDetector($mediaTypeClassifier), $mediaTypeClassifier),
                TargetNameResolverFactory::create(),
                new CollisionResolver(),
                new RenamePlanValidator(),
                $grouping,
                $visual,
                $scorer,
                new CrossGroupVideoDuplicateReconciler($mediaTypeClassifier, $matcher, $reporter, new ComparisonWorkLimit(100)),
            );
            $result = $pipeline->run(new RecursiveIteratorIterator(new RecursiveArrayIterator([])), new InheritFilenameStrategy(), new TargetBasenameStrategy(), $workspace);
            self::assertCount(1, $result->groups);
            $anchor = $result->groups->get('2025-01-01_10-00-00-000');
            self::assertInstanceOf(AssetGroup::class, $anchor);
            self::assertCount(2, $anchor->getItems());
            self::assertTrue($anchor->isClassificationDegraded());
            self::assertSame('synthetic classification failure', $anchor->getClassificationFailureReason());
            $plan = new ExecutionPlanBuilder()->build($result->groups, $result->context);
            self::assertSame(0, $plan->executableItemCount());
            self::assertSame(2, $plan->nonExecutableItemCount());

            foreach ($sources as $path) {
                self::assertSame('synthetic duplicate video', $filesystem->readFile($path));
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }
}
