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
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
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
 * Verifies the conservative reconciliation step that merges orphan MOV groups into
 * already valid Live Photo groups before subgroup classification begins.
 *
 * The tests focus on the isolated reconciliation policy: only singleton videos with
 * a content identifier qualify, duration must match exactly after normalization, and
 * the expensive perceptual comparison must produce `DuplicateLikely` before a merge
 * into the target Live Photo group is allowed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(OrphanLivePhotoVideoReconciler::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class OrphanLivePhotoVideoReconcilerTest extends TestCase
{
    private PerceptualHashCalculatorInterface&MockObject $perceptualHashCalculator;

    private BufferedOutput $output;

    private OrphanLivePhotoVideoReconciler $reconciler;

    protected function setUp(): void
    {
        $this->perceptualHashCalculator = $this->createMock(PerceptualHashCalculatorInterface::class);
        $this->output                   = new BufferedOutput();
        $this->reconciler               = new OrphanLivePhotoVideoReconciler(
            new MediaTypeClassifier(),
            $this->perceptualHashCalculator,
            new SymfonyStyle(new ArrayInput([]), $this->output),
        );
    }

    /**
     * Ensures a wrong-content-ID orphan MOV is merged into the already valid Live Photo
     * group when the companion video proves to be perceptually identical.
     *
     * This protects the later subgrouping stage from treating the orphan MOV as a
     * standalone original even though the still already has a valid companion video.
     */
    #[Test]
    public function orphanVideoDuplicateMergesIntoExistingLivePhotoGroup(): void
    {
        $still = new AssetItem(
            new SplFileInfo('/photos/Test/2019-09-28_16-57-59-738.jpg'),
            contentIdentifier: 'good-id',
        );
        $validCompanion = new AssetItem(
            new SplFileInfo('/photos/Test/2019-09-28_16-57-59-738.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2019-09-28 16:57:59'), null, false, false, null, null, null, null, null, null, 2.17),
            contentIdentifier: 'good-id',
        );
        $orphanDuplicate = new AssetItem(
            new SplFileInfo('/photos/Imported/2019-09-28_16-57-58-000.mov'),
            metadata: new TemporalMetadata(new DateTimeImmutable('2019-10-19 08:43:12'), null, false, false, null, null, null, null, null, null, 2.17),
            contentIdentifier: 'wrong-id',
        );

        $livePhotoGroup = new AssetGroup('2019-09-28_16-57-59-738');
        $livePhotoGroup->addItem($still);
        $livePhotoGroup->addItem($validCompanion);

        $orphanGroup = new AssetGroup('2019-09-28_16-57-58-000');
        $orphanGroup->addItem($orphanDuplicate);

        $groups = new AssetGroupCollection();
        $groups->set($livePhotoGroup->groupKey, $livePhotoGroup);
        $groups->set($orphanGroup->groupKey, $orphanGroup);

        $this->perceptualHashCalculator
            ->expects(self::once())
            ->method('similarityScore')
            ->willReturn(new SimilarityResult(100, 0, 0, 0.0, 0.0, 0.0, SimilarityClassification::DuplicateLikely));

        $this->perceptualHashCalculator
            ->expects(self::once())
            ->method('clearCache');

        $this->reconciler->reconcile($groups);

        self::assertCount(1, $groups);
        self::assertCount(3, $livePhotoGroup->getItems());
        self::assertInstanceOf(AssetItem::class, $livePhotoGroup->getItemByPath('/photos/Imported/2019-09-28_16-57-58-000.mov'));
        self::assertStringContainsString('Merged orphan video duplicate', implode("\n", $livePhotoGroup->getDecisionLog()));
        self::assertStringContainsString('Reconciling orphan Live Photo videos', $this->output->fetch());
    }

    /**
     * Ensures the reconciliation section stays silent when no qualifying orphan MOV
     * groups exist, keeping the normal subgroup-classification output concise.
     *
     * A group with multiple still images should bypass the orphan-only reconciliation
     * pass entirely and must therefore not trigger any progress output or hash work.
     */
    #[Test]
    public function reconciliationStepIsSkippedWhenNoOrphanVideosExist(): void
    {
        $group = new AssetGroup('2024-01-01_12-00-00');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.heic')));

        $groups = new AssetGroupCollection();
        $groups->set($group->groupKey, $group);

        $this->perceptualHashCalculator
            ->expects(self::never())
            ->method('similarityScore');

        $this->perceptualHashCalculator
            ->expects(self::never())
            ->method('clearCache');

        $this->reconciler->reconcile($groups);

        self::assertStringNotContainsString('Reconciling orphan Live Photo videos', $this->output->fetch());
    }
}
