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
use MagicSunday\Renamer\Model\OutputEntryTag;

/**
 * Selects semantic skip reasons from the exclusive output tag.
 *
 * A closed tag mapping needs no rule priorities or DI registry. Selection stays
 * centralized here, while the formatter owns plain text and the presenter owns
 * console escaping/styling. Detail fields cannot override another tag's meaning.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputSkipReasonDecider
{
    /**
     * Selects the semantic skip reason for the given entry.
     *
     * @param OutputEntry $entry Rendered output entry that should be explained to the operator
     *
     * @return OutputSkipReasonDecision Semantic decision describing the skip reason
     */
    public function decide(OutputEntry $entry): OutputSkipReasonDecision
    {
        return match ($entry->tag) {
            OutputEntryTag::Candidate => OutputSkipReasonDecision::candidateConflict(),
            OutputEntryTag::Review    => OutputSkipReasonDecision::crossGroupVideoReview($entry->reason),
            OutputEntryTag::Warning   => OutputSkipReasonDecision::warning($entry->warningReason),
            OutputEntryTag::Fallback  => OutputSkipReasonDecision::fallbackDate(),
            default                   => OutputSkipReasonDecision::genericSkipped(),
        };
    }
}
