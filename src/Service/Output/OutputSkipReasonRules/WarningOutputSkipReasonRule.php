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
 * Resolves the skip reason for warning-tagged rename entries.
 *
 * Warning entries usually already carry a specific operator-facing reason, such
 * as ambiguous timezone messaging. This rule preserves that message so the
 * formatter does not have to re-discover it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class WarningOutputSkipReasonRule implements OutputSkipReasonRuleInterface
{
    /**
     * @return int Priority below review entries and above fallback decisions
     */
    public function priority(): int
    {
        return 200;
    }

    /**
     * @param OutputEntry $entry Rendered output entry
     *
     * @return bool True when the entry is tagged as Warning
     */
    public function supports(OutputEntry $entry): bool
    {
        return $entry->tag === OutputEntryTag::Warning;
    }

    /**
     * @param OutputEntry $entry Warning entry carrying optional warning text
     *
     * @return OutputSkipReasonDecision Semantic ambiguous-timezone decision
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision
    {
        return OutputSkipReasonDecision::ambiguousTimezone($entry->warningReason);
    }
}
