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
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecision;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRuleInterface;

/**
 * Provides the generic fallback decision when no more specific skip rule applies.
 *
 * This rule deliberately has the lowest priority and always supports the entry,
 * ensuring that the decider always produces a stable semantic decision.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class DefaultOutputSkipReasonRule implements OutputSkipReasonRuleInterface
{
    /**
     * @return int Lowest priority fallback decision
     */
    public function priority(): int
    {
        return 0;
    }

    /**
     * @param OutputEntry $entry Rendered output entry
     *
     * @return bool Always true to guarantee a fallback decision
     */
    public function supports(OutputEntry $entry): bool
    {
        return true;
    }

    /**
     * @param OutputEntry $entry Rendered output entry
     *
     * @return OutputSkipReasonDecision Generic skip decision
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision
    {
        return OutputSkipReasonDecision::genericSkipped();
    }
}
