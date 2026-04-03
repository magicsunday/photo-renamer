<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

use MagicSunday\Renamer\Model\OutputEntry;

/**
 * Defines a single ordered rule contributing to skip-reason selection.
 *
 * Output rendering has a small but explicit priority problem: different tags can
 * imply different operator-facing skip reasons. Each rule declares whether it
 * applies and, when it does, contributes one semantic decision fragment.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface OutputSkipReasonRuleInterface
{
    /**
     * Returns the numeric priority used by the decider.
     *
     * Higher values win so priority never depends on accidental registration order.
     *
     * @return int Ordered priority for this rule
     */
    public function priority(): int;

    /**
     * Returns true when the rule should handle the given entry.
     *
     * @param OutputEntry $entry Rendered output entry to inspect
     *
     * @return bool True when this rule applies
     */
    public function supports(OutputEntry $entry): bool;

    /**
     * Builds the semantic skip decision for the given entry.
     *
     * @param OutputEntry $entry Rendered output entry already accepted by {@see supports()}
     *
     * @return OutputSkipReasonDecision Semantic skip decision
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision;
}
