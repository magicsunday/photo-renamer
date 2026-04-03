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
 * Resolves the skip reason for fallback-date entries.
 *
 * Fallback dates are a distinct semantic outcome because they were derived from
 * a weaker metadata field than the preferred original capture timestamp.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FallbackOutputSkipReasonRule implements OutputSkipReasonRuleInterface
{
    /**
     * @return int Priority below warning entries and above the generic fallback
     */
    public function priority(): int
    {
        return 100;
    }

    /**
     * @param OutputEntry $entry Rendered output entry
     *
     * @return bool True when the entry is tagged as Fallback
     */
    public function supports(OutputEntry $entry): bool
    {
        return $entry->tag === OutputEntryTag::Fallback;
    }

    /**
     * @param OutputEntry $entry Fallback-tagged rendered entry
     *
     * @return OutputSkipReasonDecision Semantic fallback-date decision
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision
    {
        return OutputSkipReasonDecision::fallbackDate();
    }
}
