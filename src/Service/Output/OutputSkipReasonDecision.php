<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

/**
 * Immutable decision DTO describing the semantic skip reason selected for an entry.
 *
 * The decision keeps semantic selection separate from operator-facing text.
 * Downstream formatters can therefore project the same decision into CLI text
 * without re-implementing priority logic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputSkipReasonDecision
{
    /**
     * @param OutputSkipReason $reason  Selected semantic skip reason
     * @param string|null      $message Optional custom message carried by the entry itself
     */
    private function __construct(
        public OutputSkipReason $reason,
        public ?string $message = null,
    ) {
    }

    /**
     * Creates a decision for conflicting Live Photo content identifiers.
     *
     * @return self Decision describing a candidate conflict skip
     */
    public static function candidateConflict(): self
    {
        return new self(OutputSkipReason::CandidateConflict);
    }

    /**
     * Creates a decision for a review-only output entry.
     *
     * @param string|null $message Optional review detail already prepared by the pipeline
     *
     * @return self Decision describing a cross-group review skip
     */
    public static function crossGroupVideoReview(?string $message): self
    {
        return new self(OutputSkipReason::CrossGroupVideoReview, $message);
    }

    /**
     * Creates a decision for ambiguous timezone output.
     *
     * @param string|null $message Optional warning text already present on the entry
     *
     * @return self Decision describing an ambiguous timezone skip
     */
    public static function ambiguousTimezone(?string $message): self
    {
        return new self(OutputSkipReason::AmbiguousTimezone, $message);
    }

    /**
     * Creates a decision for fallback-date output.
     *
     * @return self Decision describing a fallback date skip
     */
    public static function fallbackDate(): self
    {
        return new self(OutputSkipReason::FallbackDate);
    }

    /**
     * Creates a generic skip decision.
     *
     * @return self Decision describing a generic skip
     */
    public static function genericSkipped(): self
    {
        return new self(OutputSkipReason::GenericSkipped);
    }
}
