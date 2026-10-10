<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use Imagick;
use ImagickPixel;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\MergeDecision;
use MagicSunday\Renamer\Model\MergeDecisionKind;
use MagicSunday\Renamer\Model\MergeDecisionReason;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function array_values;
use function file_put_contents;

/**
 * Runs the real content subgroup service and its active EXIF pipeline adapter
 * against controlled Stage A decisions and real Stage B decoding. Synthetic
 * JPEG payloads use cross-format names to isolate policy from HEIC codec support.
 */
#[CoversNothing]
final class FormatBackupClassificationTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Checks that format context cannot override rejected or unavailable content
     * evidence. The active classifier must preserve the same decision on rerun.
     *
     * @param SimilarityClassification $classification Controlled Stage A result
     * @param string                   $colorA         First image color
     * @param string                   $colorB         Second image color
     * @param bool                     $decodable      Whether the second file has valid image bytes
     * @param float                    $threshold      Operator-selected maximum RMSE
     * @param bool                     $merge          Expected cluster merge decision
     * @param MergeDecisionKind        $kind           Expected evidence category
     * @param MergeDecisionReason      $reason         Expected terminal reason
     */
    #[Test]
    #[DataProvider('evidenceCases')]
    public function formatContextRespectsContentEvidence(
        SimilarityClassification $classification,
        string $colorA,
        string $colorB,
        bool $decodable,
        float $threshold,
        bool $merge,
        MergeDecisionKind $kind,
        MergeDecisionReason $reason,
    ): void {
        $workspace = $this->createTempWorkspace();

        try {
            $fileA = new SplFileInfo($workspace . '/source.heic');
            $fileB = new SplFileInfo($workspace . '/source.jpg');
            $this->writeImage($fileA, $colorA);

            if ($decodable) {
                $this->writeImage($fileB, $colorB);
            } else {
                file_put_contents($fileB->getPathname(), 'invalid image');
            }

            $similarity = self::createStub(PerceptualHashCalculatorInterface::class);
            $similarity->method('similarityScore')->willReturn(new SimilarityResult(100, 0, 0, 0.0, 0.0, null, $classification));
            $media    = new MediaTypeClassifier();
            $reporter = new NullProgressReporter();
            $service  = new HashSubGroupingService(
                new SafeHashCalculator(),
                $reporter,
                $media,
                $similarity,
                new LocalDifferenceAnalyzer(),
                new ImagickImageLoader($media),
                new ComparisonWorkLimit(100000),
            );
            $service->setMaxMergeRmse($threshold);

            $renameA = new Rename($fileA, new SplFileInfo($workspace . '/capture.heic'));
            $renameB = new Rename($fileB, new SplFileInfo($workspace . '/capture.jpg'));
            $legacy  = new FileDuplicate();
            $legacy->addFile($fileA)->addFile($fileB)->setTarget($renameA->getTarget());
            $legacy->addRename($renameA)->addRename($renameB);
            /** @var list<MergeDecision> $decisions */
            $decisions = [];
            $clusters  = $service->apply(
                $legacy,
                $renameA,
                null,
                [],
                static fn (SplFileInfo $source, string $name): string => $source->getPath() . '/' . $name,
                onDecision: static function (MergeDecision $decision) use (&$decisions): void {
                    $decisions[] = $decision;
                },
            );
            self::assertSame($merge, $clusters === null, 'The service must honor content evidence.');
            self::assertCount(1, $decisions);
            $decision = $decisions[0];
            self::assertSame($kind, $decision->kind);
            self::assertSame($reason, $decision->reason);
            self::assertSame($merge, $decision->permitsMerge());
            self::assertSame($fileA->getPathname(), $decision->leftPath);
            self::assertSame($fileB->getPathname(), $decision->rightPath);
            self::assertSame(100, $decision->similarityScore);
            self::assertNull($decision->contentHash, 'Visual similarity is not exact hash evidence.');

            if ($reason === MergeDecisionReason::ChromaVeto) {
                self::assertNotNull($decision->chromaDifference);
                self::assertGreaterThan(0.03, $decision->chromaDifference);
            }

            if (($reason === MergeDecisionReason::RmseVeto) || ($reason === MergeDecisionReason::VisualMatch)) {
                self::assertNotNull($decision->rmse);
                self::assertSame($threshold, $decision->mergeThreshold);
                self::assertSame($merge, $decision->rmse <= $threshold);
            }

            $group = new AssetGroup('capture');
            $group->addItem(new AssetItem($fileA));
            $group->addItem(new AssetItem($fileB));
            $groups = new AssetGroupCollection();
            $groups->set('capture', $group);
            $classifier = new SubgroupClassifier(
                $service,
                $media,
                new OrphanLivePhotoVideoReconciler($media, $similarity, $reporter),
                $reporter,
            );

            $classifier->classify($groups);
            self::assertFalse($group->isClassificationDegraded());
            self::assertStringContainsString($kind->value . ': ' . $reason->value, $group->getDecisionLog()[0]);
            $items         = array_values($group->getItems());
            $firstCluster  = $items[0]->clusterId;
            $secondCluster = $items[1]->clusterId;
            self::assertSame($merge, $firstCluster === $secondCluster, 'The active pipeline must preserve the service decision.');

            $classifier->classify($groups);
            $rerun = array_values($group->getItems());
            self::assertSame($firstCluster, $rerun[0]->clusterId);
            self::assertSame($secondCluster, $rerun[1]->clusterId);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Includes hard Stage A rejection, failed decoding, chroma veto, a stricter
     * user limit and positively verified compression noise at the default limit.
     *
     * @return iterable<string, array{SimilarityClassification, string, string, bool, float, bool, MergeDecisionKind, MergeDecisionReason}> Evidence cases
     */
    public static function evidenceCases(): iterable
    {
        yield 'different' => [SimilarityClassification::Different, '#808080', '#8c8c8c', true, 0.06, false, MergeDecisionKind::Different, MergeDecisionReason::PerceptualRejected];
        yield 'edited' => [SimilarityClassification::EditedVariant, '#808080', '#8c8c8c', true, 0.06, false, MergeDecisionKind::Different, MergeDecisionReason::EditedVariant];
        yield 'decode failed' => [SimilarityClassification::DuplicateLikely, '#808080', '#808080', false, 0.06, false, MergeDecisionKind::Uncertain, MergeDecisionReason::LocalAnalysisFailed];
        yield 'chroma veto' => [SimilarityClassification::DuplicateLikely, '#ff0000', '#4c4c4c', true, 1.0, false, MergeDecisionKind::Different, MergeDecisionReason::ChromaVeto];
        yield 'strict threshold' => [SimilarityClassification::DuplicateLikely, '#808080', '#8c8c8c', true, 0.0, false, MergeDecisionKind::Different, MergeDecisionReason::RmseVeto];
        yield 'verified format noise' => [SimilarityClassification::DuplicateLikely, '#808080', '#8c8c8c', true, 0.06, true, MergeDecisionKind::Perceptual, MergeDecisionReason::VisualMatch];
        yield 'identical pixels' => [SimilarityClassification::DuplicateLikely, '#808080', '#808080', true, 0.0, true, MergeDecisionKind::Perceptual, MergeDecisionReason::VisualMatch];
    }

    /**
     * Writes a small valid image with unique non-pixel metadata so content hashes
     * differ even for identical pixels and perceptual analysis is exercised.
     *
     * @param SplFileInfo $file  Synthetic image destination
     * @param string      $color Solid image color
     */
    private function writeImage(SplFileInfo $file, string $color): void
    {
        $image = new Imagick();
        $image->newImage(32, 32, new ImagickPixel($color));
        $image->setImageFormat('jpeg');
        $image->setImageProperty('comment', $file->getFilename());
        $image->writeImage($file->getPathname());
        $image->clear();
    }
}
