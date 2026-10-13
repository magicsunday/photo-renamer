<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

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
}
