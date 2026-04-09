<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

/**
 * Immutable comparison plan entry for one cross-group video pair.
 *
 * The reconciler expands duration buckets into a flat ordered list of pairs that
 * may be compared via stream fingerprints. Keeping that plan in a DTO makes the
 * left/right group and pathname semantics explicit across the execution loop.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CrossGroupVideoComparisonPlan
{
    /**
     * @param string $leftGroupKey  Group key for the left comparison side
     * @param string $leftPath      Candidate pathname for the left comparison side
     * @param string $rightGroupKey Group key for the right comparison side
     * @param string $rightPath     Candidate pathname for the right comparison side
     */
    public function __construct(
        public string $leftGroupKey,
        public string $leftPath,
        public string $rightGroupKey,
        public string $rightPath,
    ) {
    }
}
