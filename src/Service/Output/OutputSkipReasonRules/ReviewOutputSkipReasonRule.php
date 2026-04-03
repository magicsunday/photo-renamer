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
 * Resolves the skip reason for review-only output entries.
 *
 * Review entries are already semantically prepared by the pipeline. This rule
 * simply preserves that decision and passes the optional review text forward to
 * the formatter.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ReviewOutputSkipReasonRule implements OutputSkipReasonRuleInterface
{
    /**
     * @return int Priority below candidate conflicts but above generic warnings
     */
    public function priority(): int
    {
        return 300;
    }

    /**
     * @param OutputEntry $entry Rendered output entry
     *
     * @return bool True when the entry is tagged as Review
     */
    public function supports(OutputEntry $entry): bool
    {
        return $entry->tag === OutputEntryTag::Review;
    }

    /**
     * @param OutputEntry $entry Review entry carrying optional custom reason text
     *
     * @return OutputSkipReasonDecision Semantic review decision
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision
    {
        return OutputSkipReasonDecision::crossGroupVideoReview($entry->reason);
    }
}
