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
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoDuplicateReconciler;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;

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
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(VideoFingerprintMatch::class)]
#[UsesClass(VideoDuplicateCandidate::class)]
#[UsesClass(MediaCompatibilityPolicy::class)]
#[UsesClass(MediaTypeClassifier::class)]
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
            new SymfonyStyle(new ArrayInput([]), $this->output),
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
        self::assertStringContainsString('Reconciling cross-group videos', $this->output->fetch());
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
}
