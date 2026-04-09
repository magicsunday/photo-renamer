<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;

/**
 * Immutable best-match decision for one orphan video during reconciliation.
 *
 * Once a duplicate-likely perceptual match is found, the reconciler keeps only
 * the strongest score and later merges the orphan video into the winning target
 * group. This DTO replaces the previous ad-hoc `group`/`item`/`score` array.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OrphanVideoBestMatch
{
    /**
     * @param AssetGroup $group Target Live Photo group that keeps the orphan duplicate
     * @param AssetItem  $item  Existing companion video that the orphan matched against
     * @param int        $score Similarity score that justified the duplicate-likely merge
     */
    public function __construct(
        public AssetGroup $group,
        public AssetItem $item,
        public int $score,
    ) {
    }
}
