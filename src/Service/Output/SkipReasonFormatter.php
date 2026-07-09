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
 * Projects semantic skip decisions into operator-facing console text.
 *
 * The formatter deliberately contains no precedence logic. It only turns a
 * previously decided semantic reason into the exact string shown in CLI output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SkipReasonFormatter
{
    /**
     * Formats the operator-facing skip text for a semantic decision.
     *
     * @param OutputSkipReasonDecision $decision Semantic skip reason chosen by the decider
     *
     * @return string Human-readable CLI text
     */
    public function format(OutputSkipReasonDecision $decision): string
    {
        return match ($decision->reason) {
            OutputSkipReason::CandidateConflict     => 'Conflicting Live Photo content ID across groups',
            OutputSkipReason::CrossGroupVideoReview => $decision->message ?? 'Cross-group video review required',
            OutputSkipReason::AmbiguousTimezone     => $decision->message ?? 'Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone',
            OutputSkipReason::Warning               => $decision->message ?? 'Warning',
            OutputSkipReason::FallbackDate          => 'Fallback date: DateTime (0x0132) used instead of DateTimeOriginal',
            OutputSkipReason::GenericSkipped        => $decision->message ?? 'Skipped',
        };
    }
}
