<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use DateTimeImmutable;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\Pipeline\VideoFingerprintMatch;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoComparisonPlan;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoDuplicateReconciler;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoMergeDecision;
use MagicSunday\Renamer\Service\Pipeline\DurationBucketedVideoCandidate;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_values;
use function implode;
use function intdiv;
use function memory_get_usage;
use function sprintf;

use const PHP_INT_MAX;

/**
 * Verifies the cross-group video reconciliation feature track in isolation.
 *
 * These tests focus on the new policy boundary: exact stream matches move only the
 * duplicate video item, candidate cases are recorded as structured review facts,
 * and videos without a safe duration bucket are ignored before expensive matching.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CrossGroupVideoDuplicateReconciler::class)]
#[UsesClass(ComparisonWorkLimit::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(VideoFingerprintMatch::class)]
#[UsesClass(VideoDuplicateCandidate::class)]
#[UsesClass(MediaCompatibilityPolicy::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(ConsoleProgressReporter::class)]
#[UsesClass(DurationBucketedVideoCandidate::class)]
#[UsesClass(CrossGroupVideoComparisonPlan::class)]
#[UsesClass(CrossGroupVideoMergeDecision::class)]
final class CrossGroupVideoDuplicateReconcilerTest extends TestCase
{
    private VideoStreamFingerprintMatcherInterface&MockObject $matcher;

    private BufferedOutput $output;

    private CrossGroupVideoDuplicateReconciler $reconciler;

    protected function setUp(): void
    {
        $this->matcher    = $this->createMock(VideoStreamFingerprintMatcherInterface::class);
        $this->output     = new BufferedOutput();
        $this->reconciler = new CrossGroupVideoDuplicateReconciler(
            new MediaCompatibilityPolicy(new MediaTypeClassifier()),
            $this->matcher,
            new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $this->output)),
            new ComparisonWorkLimit(100000),
        );
    }

    /**
     * Verifies that an exact duplicate video match moves only the duplicate item
     * into the earlier anchor group and removes the now-empty source group.
     *
     * The reconciler must not merge whole groups, because a timestamp-split source
     * group could still contain unrelated files that belong to a different capture.
     * It must also prefer the earlier group key over lexicographic path order when
     * choosing the anchor group for the merge.
     */
    #[Test]
    public function exactDuplicateMovesOnlyTheVideoItem(): void
    {
        $context = new PipelineContext('/photos');

        $anchorVideo = new AssetItem(
            new SplFileInfo('/photos/z/clip.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2025-01-01 10:00:00'), null, false, false, null, null, null, null, null, null, 2.17),
        );
        $duplicateVideo = new AssetItem(
            new SplFileInfo('/photos/a/clip.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2025-01-02 10:00:00'), null, false, false, null, null, null, null, null, null, 2.17),
        );

        $anchorGroup = new AssetGroup('2025-01-01_10-00-00-000');
        $anchorGroup->addItem($anchorVideo);

        $duplicateGroup = new AssetGroup('2025-01-02_10-00-00-000');
        $duplicateGroup->addItem($duplicateVideo);

        $groups = new AssetGroupCollection();
        $groups->set($anchorGroup->groupKey, $anchorGroup);
        $groups->set($duplicateGroup->groupKey, $duplicateGroup);

        $this->matcher
            ->expects(self::once())
            ->method('match')
            ->willReturn(new VideoFingerprintMatch(true, true, false, false, false));

        $this->reconciler->reconcile($groups, $context);

        self::assertCount(1, $groups);
        self::assertCount(2, $anchorGroup->getItems());
        self::assertInstanceOf(AssetItem::class, $anchorGroup->getItemByPath('/photos/a/clip.mov'));
        self::assertSame([], $context->getVideoDuplicateCandidates());
        self::assertStringContainsString('Merged cross-group video duplicate', implode("\n", $anchorGroup->getDecisionLog()));
    }

    /**
     * Verifies that a review-only fingerprint result records a structured candidate
     * instead of changing the grouping.
     *
     * This protects the conservative policy around audio mismatches: the user gets
     * a visible follow-up finding, but the pipeline does not silently merge files.
     */
    #[Test]
    public function candidateMatchAddsStructuredReviewFactWithoutMerging(): void
    {
        $context = new PipelineContext('/photos');

        $videoA = new AssetItem(
            new SplFileInfo('/photos/2025/clip.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2025-01-01 10:00:00'), null, false, false, null, null, null, null, null, null, 2.17),
        );
        $videoB = new AssetItem(
            new SplFileInfo('/photos/archive/clip.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2025-01-02 10:00:00'), null, false, false, null, null, null, null, null, null, 2.17),
        );

        $groupA = new AssetGroup('2025-01-01_10-00-00-000');
        $groupA->addItem($videoA);

        $groupB = new AssetGroup('2025-01-02_10-00-00-000');
        $groupB->addItem($videoB);

        $groups = new AssetGroupCollection();
        $groups->set($groupA->groupKey, $groupA);
        $groups->set($groupB->groupKey, $groupB);

        $this->matcher
            ->expects(self::once())
            ->method('match')
            ->willReturn(new VideoFingerprintMatch(
                true,
                false,
                false,
                false,
                true,
                'video stream identical, audio differs',
            ));

        $this->reconciler->reconcile($groups, $context);

        self::assertCount(2, $groups);
        self::assertCount(1, $context->getVideoDuplicateCandidates());
        self::assertSame('/photos/archive/clip.mov', $context->getVideoDuplicateCandidates()[0]->counterpartPath);
        self::assertStringContainsString('Reconciling cross-group videos', $this->output->fetch());
    }

    /**
     * Verifies that conflicting non-null content identifiers block the stream-level
     * fallback entirely, even when duration bucketing would otherwise compare the pair.
     *
     * Feature Track A is only allowed to bridge metadata splits when the stronger
     * Live Photo identity signal is missing or agrees on both sides. Two different
     * content identifiers represent two different Live Photo pairs, so the matcher
     * must not overrule that distinction with identical stream hashes.
     */
    #[Test]
    public function conflictingContentIdentifiersSuppressCrossGroupStreamComparison(): void
    {
        $context = new PipelineContext('/photos');

        $videoA = new AssetItem(
            new SplFileInfo('/photos/2025/clip.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2025-01-01 10:00:00'), null, false, false, null, null, null, null, null, null, 2.17),
            contentIdentifier: 'aaa',
        );
        $videoB = new AssetItem(
            new SplFileInfo('/photos/archive/clip.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2025-01-02 10:00:00'), null, false, false, null, null, null, null, null, null, 2.17),
            contentIdentifier: 'bbb',
        );

        $groupA = new AssetGroup('2025-01-01_10-00-00-000');
        $groupA->addItem($videoA);

        $groupB = new AssetGroup('2025-01-02_10-00-00-000');
        $groupB->addItem($videoB);

        $groups = new AssetGroupCollection();
        $groups->set($groupA->groupKey, $groupA);
        $groups->set($groupB->groupKey, $groupB);

        $this->matcher
            ->expects(self::never())
            ->method('match');

        $this->reconciler->reconcile($groups, $context);

        self::assertCount(2, $groups);
        self::assertSame([], $context->getVideoDuplicateCandidates());
        self::assertSame('', $this->output->fetch());
    }

    /**
     * Verifies that videos without a normalized duration bucket are ignored before
     * the matcher is ever invoked.
     *
     * The duration pre-filter is part of the current safety policy, so missing
     * duration metadata must suppress the feature rather than widening its reach.
     */
    #[Test]
    public function missingDurationSkipsTheFeatureBeforeFingerprinting(): void
    {
        $context = new PipelineContext('/photos');

        $groupA = new AssetGroup('2025-01-01_10-00-00-000');
        $groupA->addItem(new AssetItem(new SplFileInfo('/photos/2025/clip.mov')));

        $groupB = new AssetGroup('2025-01-02_10-00-00-000');
        $groupB->addItem(new AssetItem(new SplFileInfo('/photos/archive/clip.mov')));

        $groups = new AssetGroupCollection();
        $groups->set($groupA->groupKey, $groupA);
        $groups->set($groupB->groupKey, $groupB);

        $this->matcher
            ->expects(self::never())
            ->method('match');

        $this->reconciler->reconcile($groups, $context);

        self::assertSame([], $context->getVideoDuplicateCandidates());
        self::assertSame('', $this->output->fetch());
    }

    /**
     * Reaching a deliberately tiny batch budget must stop before the next stream
     * comparison rather than silently accepting an unbounded duration bucket.
     */
    #[Test]
    public function stopsCrossGroupAnalysisWhenThePairBudgetIsExceeded(): void
    {
        $groups = $this->createDistinctGroups(4);
        $calls  = 0;
        $this->matcher->expects(self::exactly(2))->method('match')->willReturnCallback(static function () use (&$calls): VideoFingerprintMatch {
            ++$calls;

            return new VideoFingerprintMatch(false, false, false, false, false);
        });
        $reconciler = new CrossGroupVideoDuplicateReconciler(
            new MediaCompatibilityPolicy(new MediaTypeClassifier()),
            $this->matcher,
            new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $this->output)),
            new ComparisonWorkLimit(2),
        );

        $failure = null;

        try {
            $reconciler->reconcile($groups, new PipelineContext('/synthetic'));
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertStringContainsString('MAX_COMPARISON_PAIRS=2', $failure->getMessage());
        self::assertSame(2, $calls);
        self::assertCount(4, $groups);
    }

    /**
     * The first stream comparison in a 400-video bucket must begin without first
     * allocating its 79,800 pair objects. Stop at that observable boundary so the
     * regression is fast and never performs a large native-media workload.
     */
    #[Test]
    public function startsMatchingWithoutMaterializingTheQuadraticPairList(): void
    {
        $groups       = $this->createDistinctGroups(400);
        $memoryBefore = memory_get_usage();
        $growth       = 0;
        $this->matcher->expects(self::exactly(1))->method('match')->willReturnCallback(static function () use ($memoryBefore, &$growth): never {
            $growth = memory_get_usage() - $memoryBefore;

            throw new RuntimeException('First comparison observed.');
        });

        $failure = null;

        try {
            $this->reconciler->reconcile($groups, new PipelineContext('/synthetic'));
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('First comparison observed.', $failure->getMessage());

        self::assertLessThan(8 * 1024 * 1024, $growth, 'Planning alone must stay below 8 MiB for this synthetic bucket.');
    }

    /**
     * The lazy iterator must retain the previous nested pathname order and the
     * same unmatched groups when all six pairs fit within an exact budget.
     */
    #[Test]
    public function preservesSmallBucketComparisonOrder(): void
    {
        $visited = [];
        $this->matcher->expects(self::exactly(6))->method('match')->willReturnCallback(static function (SplFileInfo $left, SplFileInfo $right) use (&$visited): VideoFingerprintMatch {
            $visited[] = [$left->getPathname(), $right->getPathname()];

            return new VideoFingerprintMatch(false, false, false, false, false);
        });
        $groups = $this->createDistinctGroups(4);
        $this->reconciler->reconcile($groups, new PipelineContext('/synthetic'));

        self::assertSame([
            ['/synthetic/video-0000.mov', '/synthetic/video-0001.mov'],
            ['/synthetic/video-0000.mov', '/synthetic/video-0002.mov'],
            ['/synthetic/video-0000.mov', '/synthetic/video-0003.mov'],
            ['/synthetic/video-0001.mov', '/synthetic/video-0002.mov'],
            ['/synthetic/video-0001.mov', '/synthetic/video-0003.mov'],
            ['/synthetic/video-0002.mov', '/synthetic/video-0003.mov'],
        ], $visited);
        self::assertCount(4, $groups);
    }

    /**
     * Interleaved known and unknown IDs retain the old nested pathname order;
     * unknown IDs compare to every identity and equal known IDs remain eligible.
     */
    #[Test]
    public function preservesComparisonOrderForInterleavedContentIdentifiers(): void
    {
        $groups      = $this->createDistinctGroups(5);
        $identifiers = ['a', 'b', null, 'a', null];

        foreach (array_values($groups->asArray()) as $index => $group) {
            $item = $group->getItems()[0];
            $group->replaceItem($item, new AssetItem($item->file, metadata: $item->metadata, contentIdentifier: $identifiers[$index]));
        }

        $visited = [];
        $this->matcher->expects(self::exactly(8))->method('match')->willReturnCallback(static function (SplFileInfo $left, SplFileInfo $right) use (&$visited): VideoFingerprintMatch {
            $visited[] = [$left->getBasename(), $right->getBasename()];

            return new VideoFingerprintMatch(false, false, false, false, false);
        });
        $this->reconciler->reconcile($groups, new PipelineContext('/synthetic'));
        self::assertSame([
            ['video-0000.mov', 'video-0002.mov'],
            ['video-0000.mov', 'video-0003.mov'],
            ['video-0000.mov', 'video-0004.mov'],
            ['video-0001.mov', 'video-0002.mov'],
            ['video-0001.mov', 'video-0004.mov'],
            ['video-0002.mov', 'video-0003.mov'],
            ['video-0002.mov', 'video-0004.mov'],
            ['video-0003.mov', 'video-0004.mov'],
        ], $visited);
        self::assertCount(5, $groups);
    }

    /**
     * Separate duration buckets share one batch budget, rather than resetting
     * it for every bucket and allowing unbounded aggregate work.
     */
    #[Test]
    public function enforcesOneBudgetAcrossDurationBuckets(): void
    {
        $this->matcher->expects(self::once())->method('match')->willReturn(new VideoFingerprintMatch(false, false, false, false, false));
        $reconciler = new CrossGroupVideoDuplicateReconciler(new MediaCompatibilityPolicy(new MediaTypeClassifier()), $this->matcher, new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $this->output)), new ComparisonWorkLimit(1));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cross-group video batch/');
        $reconciler->reconcile($this->createDistinctGroups(4, 2), new PipelineContext('/synthetic'));
    }

    /**
     * A large duration bucket with distinct known Live Photo identifiers has no
     * eligible stream pairs. Indexing must exclude these in linear work instead
     * of walking every impossible pair and exhausting a one-pair budget.
     *
     * @param int $count Synthetic known-ID candidates, without real files or decoding
     */
    #[Test]
    #[DataProvider('knownIdentifierBucketSizes')]
    public function excludesDistinctKnownContentIdentifiersWithoutWalkingTheirPairs(int $count): void
    {
        $groups = $this->createDistinctGroups($count);

        foreach ($groups as $group) {
            $item = $group->getItems()[0];
            $group->replaceItem($item, new AssetItem($item->file, metadata: $item->metadata, contentIdentifier: $group->groupKey));
        }

        $this->matcher->expects(self::never())->method('match');
        $reconciler = new CrossGroupVideoDuplicateReconciler(new MediaCompatibilityPolicy(new MediaTypeClassifier()), $this->matcher, new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $this->output)), new ComparisonWorkLimit(1));
        $reconciler->reconcile($groups, new PipelineContext('/synthetic'));
        self::assertCount($count, $groups);
        self::assertSame('', $this->output->fetch());
    }

    /**
     * @return iterable<string, array{int}> Small regression and large metadata-only collection scale
     */
    public static function knownIdentifierBucketSizes(): iterable
    {
        yield '1000 known identifiers' => [1000];
        yield '70000 known identifiers' => [70000];
    }

    /**
     * A duration bucket wholly inside one capture group requires no cross-group
     * pair walk and must not exhaust a tiny budget just because it has many items.
     */
    #[Test]
    public function skipsSingleGroupBucketsWithoutConsumingPairBudget(): void
    {
        $groups = $this->createDistinctGroups(4);
        $single = new AssetGroup('single');

        foreach ($groups as $group) {
            $single->addItem($group->getItems()[0]);
        }

        $groups = new AssetGroupCollection();
        $groups->set('single', $single);
        $this->matcher->expects(self::never())->method('match');
        $reconciler = new CrossGroupVideoDuplicateReconciler(new MediaCompatibilityPolicy(new MediaTypeClassifier()), $this->matcher, new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $this->output)), new ComparisonWorkLimit(1));
        $reconciler->reconcile($groups, new PipelineContext('/synthetic'));
        self::assertSame(4, $single->itemCount());
        self::assertSame('', $this->output->fetch());
    }

    /**
     * Reusing the immutable policy and service across batches must not retain
     * consumed work; each one-pair batch fits independently into a one-pair budget.
     */
    #[Test]
    public function resetsVisitedPairCountForEachBatch(): void
    {
        $this->matcher->expects(self::exactly(2))->method('match')->willReturn(new VideoFingerprintMatch(false, false, false, false, false));
        $reconciler = new CrossGroupVideoDuplicateReconciler(new MediaCompatibilityPolicy(new MediaTypeClassifier()), $this->matcher, new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $this->output)), new ComparisonWorkLimit(1));
        $first      = $this->createDistinctGroups(2);
        $second     = $this->createDistinctGroups(2);
        $reconciler->reconcile($first, new PipelineContext('/synthetic'));
        $reconciler->reconcile($second, new PipelineContext('/synthetic'));
        self::assertCount(2, $first);
        self::assertCount(2, $second);
    }

    /**
     * Creates metadata-only candidates with controlled duration and distinct capture
     * groups; no files are created or decoded by these planning regressions.
     *
     * @param int $count      Number of video groups
     * @param int $bucketSize Number of candidates with each duration
     *
     * @return AssetGroupCollection Synthetic bucket in deterministic pathname order
     */
    private function createDistinctGroups(int $count, int $bucketSize = PHP_INT_MAX): AssetGroupCollection
    {
        $groups = new AssetGroupCollection();

        for ($index = 0; $index < $count; ++$index) {
            $group = new AssetGroup(sprintf('capture-%04d', $index));
            $group->addItem(new AssetItem(
                new SplFileInfo(sprintf('/synthetic/video-%04d.mov', $index)),
                metadata: new TemporalMetadata(new DateTimeImmutable('2024-01-01'), null, false, false, null, null, null, null, null, null, 2.0 + intdiv($index, $bucketSize)),
            ));
            $groups->set($group->groupKey, $group);
        }

        return $groups;
    }
}
