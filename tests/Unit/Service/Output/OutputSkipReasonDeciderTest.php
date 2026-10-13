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
use MagicSunday\Renamer\Service\Output\SkipReasonFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
/**
 * Verifies the local output skip-reason policy layer.
 *
 * Tests preserve every exclusive tag's semantic category and exact formatted
 * text, including null/empty/custom detail handling. Selection is independent
 * from rendering and cannot be overridden by unrelated detail fields.
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
        $decider   = new OutputSkipReasonDecider();
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
        $decider   = new OutputSkipReasonDecider();
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
        $decider   = new OutputSkipReasonDecider();
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
     * skip decision from the tag mapping default.
     */
    #[Test]
    public function genericSkippedEntriesFallBackToGenericDecision(): void
    {
        $decider   = new OutputSkipReasonDecider();
        $formatter = new SkipReasonFormatter();

        $decision = $decider->decide(
            $this->createSkippedRenameEntry(OutputEntryTag::Original),
        );

        self::assertSame(OutputSkipReason::GenericSkipped, $decision->reason);
        self::assertSame('Skipped', $formatter->format($decision));
    }

    /**
     * Preserves every tag's semantic category, optional detail and final plain
     * output text. Null and empty messages remain distinct; unrelated reason
     * fields must not change tag selection or leak into generic skip output.
     *
     * @param OutputEntryTag   $tag             Exclusive output category
     * @param string|null      $reason          Pipeline review detail, potentially unrelated to the tag
     * @param string|null      $warning         Supplemental warning detail
     * @param OutputSkipReason $expectedReason  Established semantic skip category
     * @param string|null      $expectedMessage Detail retained by the semantic decision
     * @param string           $expectedText    Established formatter output, including empty messages
     *
     * @return void
     */
    #[Test]
    #[DataProvider('tagDecisions')]
    public function everyTagPreservesItsDecisionAndConditionalDetail(
        OutputEntryTag $tag,
        ?string $reason,
        ?string $warning,
        OutputSkipReason $expectedReason,
        ?string $expectedMessage,
        string $expectedText,
    ): void {
        $entry = new OutputEntry(
            sortKey: '/synthetic/source',
            type: OutputEntryType::Rename,
            tag: $tag,
            sourcePath: '/synthetic/source',
            shouldSkip: true,
            reason: $reason,
            warningReason: $warning,
        );
        $decision = new OutputSkipReasonDecider()->decide($entry);

        self::assertSame($expectedReason, $decision->reason);
        self::assertSame($expectedMessage, $decision->message);
        self::assertSame($expectedText, new SkipReasonFormatter()->format($decision));
    }

    /**
     * Enumerates the established specific and default decisions independently
     * from production selection, including null/empty/custom detail boundaries.
     *
     * @return iterable<string, array{OutputEntryTag, string|null, string|null, OutputSkipReason, string|null, string}> Semantic/text compatibility cases
     */
    public static function tagDecisions(): iterable
    {
        yield 'candidate ignores other details' => [OutputEntryTag::Candidate, 'unrelated reason', 'unrelated warning', OutputSkipReason::CandidateConflict, null, 'Conflicting Live Photo content ID across groups'];
        yield 'fallback ignores other details' => [OutputEntryTag::Fallback, 'unrelated reason', 'unrelated warning', OutputSkipReason::FallbackDate, null, 'Fallback date: DateTime (0x0132) used instead of DateTimeOriginal'];
        yield 'review null' => [OutputEntryTag::Review, null, 'unrelated warning', OutputSkipReason::CrossGroupVideoReview, null, 'Cross-group video review required'];
        yield 'review empty' => [OutputEntryTag::Review, '', 'unrelated warning', OutputSkipReason::CrossGroupVideoReview, '', ''];
        yield 'review custom' => [OutputEntryTag::Review, 'Synthetic video review', 'unrelated warning', OutputSkipReason::CrossGroupVideoReview, 'Synthetic video review', 'Synthetic video review'];
        yield 'warning null' => [OutputEntryTag::Warning, 'unrelated reason', null, OutputSkipReason::Warning, null, 'Warning'];
        yield 'warning empty' => [OutputEntryTag::Warning, 'unrelated reason', '', OutputSkipReason::Warning, '', ''];
        yield 'warning timezone remains generic warning' => [OutputEntryTag::Warning, 'unrelated reason', 'Ambiguous timezone: custom warning', OutputSkipReason::Warning, 'Ambiguous timezone: custom warning', 'Ambiguous timezone: custom warning'];

        foreach ([OutputEntryTag::Rename, OutputEntryTag::Duplicate, OutputEntryTag::Original, OutputEntryTag::Skipped, OutputEntryTag::Error, OutputEntryTag::Info] as $tag) {
            yield $tag->value . ' generic null' => [$tag, null, null, OutputSkipReason::GenericSkipped, null, 'Skipped'];
            yield $tag->value . ' generic ignores details' => [$tag, 'unrelated reason', 'unrelated warning', OutputSkipReason::GenericSkipped, null, 'Skipped'];
        }
    }

    /**
     * Creates a minimal skipped rename entry for semantic decision testing.
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
