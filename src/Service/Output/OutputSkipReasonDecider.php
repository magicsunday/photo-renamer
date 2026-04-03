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

use function is_array;
use function iterator_to_array;
use function usort;

/**
 * Applies the ordered skip-reason rules for output entries.
 *
 * The decider owns precedence so output rendering no longer relies on inline
 * `match` expressions. This keeps policy selection centralized and makes the
 * renderer responsible only for presentation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputSkipReasonDecider
{
    /**
     * @var list<OutputSkipReasonRuleInterface>
     */
    private array $rules;

    /**
     * @param iterable<OutputSkipReasonRuleInterface> $rules Ordered skip-reason rules supplied by DI or tests
     */
    public function __construct(iterable $rules)
    {
        $rules = is_array($rules)
            ? $rules
            : iterator_to_array($rules, false);

        usort($rules, static fn (OutputSkipReasonRuleInterface $left, OutputSkipReasonRuleInterface $right): int => $right->priority() <=> $left->priority());

        $this->rules = $rules;
    }

    /**
     * Selects the semantic skip reason for the given entry.
     *
     * @param OutputEntry $entry Rendered output entry that should be explained to the operator
     *
     * @return OutputSkipReasonDecision Semantic decision describing the skip reason
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision
    {
        foreach ($this->rules as $rule) {
            if ($rule->supports($entry)) {
                return $rule->decide($entry);
            }
        }

        return OutputSkipReasonDecision::genericSkipped();
    }
}
