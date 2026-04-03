<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output\OutputSkipReasonRules;

use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecision;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRuleInterface;

/**
 * Resolves the skip reason for Live Photo candidate conflicts.
 *
 * Candidate entries reflect a hard content-identifier conflict and must therefore
 * win over the generic skip fallback whenever they are present.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CandidateOutputSkipReasonRule implements OutputSkipReasonRuleInterface
{
    /**
     * @return int High priority for candidate conflict decisions
     */
    public function priority(): int
    {
        return 400;
    }

    /**
     * @param OutputEntry $entry Rendered output entry
     *
     * @return bool True when the entry is tagged as Candidate
     */
    public function supports(OutputEntry $entry): bool
    {
        return $entry->tag === OutputEntryTag::Candidate;
    }

    /**
     * @param OutputEntry $entry Candidate entry requiring operator review
     *
     * @return OutputSkipReasonDecision Semantic candidate-conflict decision
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision
    {
        return OutputSkipReasonDecision::candidateConflict();
    }
}
