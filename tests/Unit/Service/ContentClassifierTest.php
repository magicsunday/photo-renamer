<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Classification\ContentClassificationResult;
use MagicSunday\Renamer\Model\MergeDecision;
use MagicSunday\Renamer\Model\MergeDecisionKind;
use MagicSunday\Renamer\Model\MergeDecisionReason;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\ContentClassifier;
use MagicSunday\Renamer\Service\DisjointSetUnion;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityClassification;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Protects content membership from caller naming and canonical rank preferences.
 * Native safety and legacy/direct differential cases live in the integration
 * suites; this test isolates the source-only contract and lossless exact evidence.
 */
#[CoversClass(ContentClassifier::class)]
#[CoversClass(ContentClassificationResult::class)]
#[UsesClass(ComparisonWorkLimit::class)]
#[UsesClass(DisjointSetUnion::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(LocalDifferenceAnalyzer::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MergeDecision::class)]
#[UsesClass(MergeDecisionKind::class)]
#[UsesClass(MergeDecisionReason::class)]
#[UsesClass(NullProgressReporter::class)]
#[UsesClass(SimilarityResult::class)]
final class ContentClassifierTest extends TestCase
{
    /**
     * An exact duplicate joins its source while unavailable visual evidence keeps
     * another same-stem source separate. Changing the preferred source only
     * changes ranks; repeated analysis preserves groups and every evidence field.
     * No target filename, naming callback or filesystem mutation is supplied.
     *
     * @return void
     */
    #[Test]
    public function preferredSourceChangesOnlyRanksAndPreservesExactEvidence(): void
    {
        $first   = new SplFileInfo('/synthetic/capture.jpg');
        $other   = new SplFileInfo('/synthetic/capture.heic');
        $copy    = new SplFileInfo('/synthetic/copy.jpg');
        $files   = [$first, $other, $copy];
        $hashing = self::createStub(SafeHashCalculatorInterface::class);
        $hashing->method('hashFile')->willReturnCallback(
            static fn (SplFileInfo $file, string $algorithm): string => $file === $other ? 'other-hash' : 'exact-hash',
        );
        $visual = self::createStub(PerceptualHashCalculatorInterface::class);
        $visual->method('similarityScore')->willReturn(new SimilarityResult(0, 0, 0, 0.0, 0.0, null, SimilarityClassification::Different, false));
        $media             = new MediaTypeClassifier();
        $reporter          = new NullProgressReporter();
        $classifier        = new ContentClassifier($hashing, $reporter, $media, $visual, new LocalDifferenceAnalyzer(), new ImagickImageLoader($media, $reporter), new ComparisonWorkLimit(100));
        $previousDecisions = null;

        foreach ([$first, $other, $first] as $preferred) {
            $decisions = [];
            $result    = $classifier->classify($files, $preferred, null, onDecision: static function (MergeDecision $decision) use (&$decisions): void {
                $decisions[] = $decision;
            });
            self::assertSame(['exact-hash' => [$first, $copy], 'other-hash' => [$other]], $result->groups);
            self::assertSame($files, $result->classifiedFiles);
            self::assertSame(['exact-hash' => $preferred === $first ? 0 : 2, 'other-hash' => $preferred === $other ? 0 : 2], $result->groupRanks());
            self::assertCount(2, $decisions);
            self::assertEquals(new MergeDecision(MergeDecisionKind::Exact, MergeDecisionReason::ContentHashMatch, $first->getPathname(), $copy->getPathname(), contentHash: 'exact-hash'), $decisions[0]);
            self::assertSame(MergeDecisionKind::Uncertain, $decisions[1]->kind);
            self::assertSame(MergeDecisionReason::SignalUnavailable, $decisions[1]->reason);

            if ($previousDecisions !== null) {
                self::assertEquals($previousDecisions, $decisions);
            }

            $previousDecisions = $decisions;
            $classifier->clearCache();
        }
    }

    /**
     * Reused Apple IDs across rejected still variants cannot assign both videos
     * to the last still encountered. Matching basenames preserve each owner in
     * either source order while the still content clusters remain separate.
     */
    #[Test]
    public function ambiguousIdentifierUsesMatchingBasenameWithoutMergingStills(): void
    {
        $original       = new SplFileInfo('/synthetic/original.jpg');
        $edited         = new SplFileInfo('/synthetic/edited.heic');
        $originalVideo  = new SplFileInfo('/synthetic/original.mov');
        $editedVideo    = new SplFileInfo('/synthetic/edited.mov');
        $copyVideo      = new SplFileInfo('/synthetic/original-duplicate-001.mov');
        $unmatchedVideo = new SplFileInfo('/synthetic/unmatched.mov');
        $hashing        = self::createStub(SafeHashCalculatorInterface::class);
        $hashing->method('hashFile')->willReturnCallback(static fn (SplFileInfo $file, string $algorithm): string => $file === $original ? 'original-root' : 'edited-root');
        $visual = self::createStub(PerceptualHashCalculatorInterface::class);
        $visual->method('similarityScore')->willReturn(new SimilarityResult(0, 0, 0, 0.0, 0.0, null, SimilarityClassification::Different, false));
        $media       = new MediaTypeClassifier();
        $reporter    = new NullProgressReporter();
        $classifier  = new ContentClassifier($hashing, $reporter, $media, $visual, new LocalDifferenceAnalyzer(), new ImagickImageLoader($media, $reporter), new ComparisonWorkLimit(100));
        $identifiers = [];

        foreach ([$original, $edited, $originalVideo, $editedVideo, $copyVideo, $unmatchedVideo] as $file) {
            $identifiers[$file->getPathname()] = 'shared-original-identifier';
        }

        foreach ([[$original, $edited, $originalVideo, $editedVideo, $copyVideo, $unmatchedVideo], [$edited, $original, $editedVideo, $originalVideo, $copyVideo, $unmatchedVideo]] as $files) {
            $result = $classifier->classify($files, $original, $originalVideo, $identifiers);
            self::assertCount(2, $result->groups);
            self::assertSame('original-root', $result->companionClusters[$originalVideo->getPathname()]);
            self::assertSame('edited-root', $result->companionClusters[$editedVideo->getPathname()]);
            self::assertSame('original-root', $result->companionClusters[$copyVideo->getPathname()]);
            self::assertArrayNotHasKey($unmatchedVideo->getPathname(), $result->companionClusters);
            $classifier->clearCache();
        }
    }
}
