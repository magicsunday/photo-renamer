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
 * Enumerates the semantic reasons why a rendered rename entry is skipped.
 *
 * The output layer distinguishes the operator-facing wording from the
 * underlying tag. This avoids repeating `match` expressions in renderers and
 * execution services while keeping the final console text centralized.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum OutputSkipReason
{
    /**
     * Conflicting Live Photo identifiers prevent an automatic merge.
     */
    case CandidateConflict;

    /**
     * A review-only item must stay visible but must not execute automatically.
     */
    case CrossGroupVideoReview;

    /**
     * QuickTime timestamps are structurally ambiguous without a timezone.
     */
    case AmbiguousTimezone;

    /**
     * A fallback metadata field supplied the date instead of the primary field.
     */
    case FallbackDate;

    /**
     * Generic skip used when no more specific semantic reason applies.
     */
    case GenericSkipped;
}
