<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Output;

use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\OutputEntryType;
use MagicSunday\Renamer\Service\Output\DiffTokenState;
use MagicSunday\Renamer\Service\Output\OutputSkipReason;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecider;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecision;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\CandidateOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\DefaultOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\FallbackOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\ReviewOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\WarningOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\SkipReasonFormatter;
use MagicSunday\Renamer\Test\Fixtures\OutputSkipReasonRuleFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputSkipReasonDecider::class)]
#[CoversClass(SkipReasonFormatter::class)]
#[UsesClass(OutputEntry::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(OutputEntryType::class)]
#[UsesClass(DiffTokenState::class)]
#[UsesClass(OutputSkipReason::class)]
#[UsesClass(OutputSkipReasonDecision::class)]
#[UsesClass(CandidateOutputSkipReasonRule::class)]
#[UsesClass(DefaultOutputSkipReasonRule::class)]
#[UsesClass(FallbackOutputSkipReasonRule::class)]
#[UsesClass(ReviewOutputSkipReasonRule::class)]
#[UsesClass(WarningOutputSkipReasonRule::class)]
/**
 * Verifies the local output skip-reason policy layer.
 *
 * These tests lock the ordered decision behavior used by RenameOutputRenderer so
 * skip reasons no longer depend on repeated inline `match` expressions in
 * multiple services.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class OutputSkipReasonDeciderTest extends TestCase
{
    /**
     * Verifies that a candidate-tagged entry resolves to the explicit content-ID
     * conflict reason instead of falling through to a generic skip message.
     */
    #[Test]
    public function candidateEntriesUseCandidateConflictReason(): void
    {
        $decider   = new OutputSkipReasonDecider(OutputSkipReasonRuleFactory::createDefaultRules());
        $formatter = new SkipReasonFormatter();

        $decision = $decider->decide(
            $this->createSkippedRenameEntry(OutputEntryTag::Candidate),
        );

        self::assertSame(OutputSkipReason::CandidateConflict, $decision->reason);
        self::assertSame('Conflicting Live Photo content ID across groups', $formatter->format($decision));
    }

    /**
     * Verifies that review-tagged entries preserve the custom review message
     * produced upstream by the pipeline.
     */
    #[Test]
    public function reviewEntriesPreserveCustomReasonText(): void
    {
        $decider   = new OutputSkipReasonDecider(OutputSkipReasonRuleFactory::createDefaultRules());
        $formatter = new SkipReasonFormatter();

        $decision = $decider->decide(
            new OutputEntry(
                sortKey: '/tmp/source/clip.mov',
                type: OutputEntryType::Rename,
                tag: OutputEntryTag::Review,
                sourcePath: 'clip.mov',
                targetPath: 'clip.mov',
                shouldSkip: true,
                reason: 'Cross-group video review: identical stream, conflicting metadata',
            ),
        );

        self::assertSame(OutputSkipReason::CrossGroupVideoReview, $decision->reason);
        self::assertSame(
            'Cross-group video review: identical stream, conflicting metadata',
            $formatter->format($decision),
        );
    }

    /**
     * Verifies that warning-tagged entries prefer the warning text stored on the
     * output entry instead of replacing it with a generic fallback.
     */
    #[Test]
    public function warningEntriesUseStoredWarningReason(): void
    {
        $decider   = new OutputSkipReasonDecider(OutputSkipReasonRuleFactory::createDefaultRules());
        $formatter = new SkipReasonFormatter();

        $decision = $decider->decide(
            OutputEntry::rename(
                sortKey: '/tmp/source/clip.mov',
                sourcePath: 'clip.mov',
                targetPath: 'clip.mov',
                tag: OutputEntryTag::Warning,
                shouldSkip: true,
                warningReason: 'Ambiguous timezone: custom warning',
            ),
        );

        self::assertSame(OutputSkipReason::Warning, $decision->reason);
        self::assertSame('Ambiguous timezone: custom warning', $formatter->format($decision));
    }

    /**
     * Verifies that unclassified skipped entries still receive a stable generic
     * skip decision from the fallback rule.
     */
    #[Test]
    public function genericSkippedEntriesFallBackToGenericDecision(): void
    {
        $decider   = new OutputSkipReasonDecider(OutputSkipReasonRuleFactory::createDefaultRules());
        $formatter = new SkipReasonFormatter();

        $decision = $decider->decide(
            $this->createSkippedRenameEntry(OutputEntryTag::Original),
        );

        self::assertSame(OutputSkipReason::GenericSkipped, $decision->reason);
        self::assertSame('Skipped', $formatter->format($decision));
    }

    /**
     * Creates a minimal skipped rename entry for rule testing.
     *
     * @param OutputEntryTag $tag Tag used to drive skip-reason resolution
     *
     * @return OutputEntry Rendered rename entry marked as skipped
     */
    private function createSkippedRenameEntry(OutputEntryTag $tag): OutputEntry
    {
        return OutputEntry::rename(
            sortKey: '/tmp/source/example',
            sourcePath: 'example',
            targetPath: 'example',
            tag: $tag,
            shouldSkip: true,
        );
    }
}
