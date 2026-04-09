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
 * Immutable candidate representing a video that already belongs to a valid
 * still+video Live Photo pair.
 *
 * The orphan-video reconciler compares standalone MOV groups against these
 * existing valid companion videos before subgroup classification. This DTO makes
 * the group/item pairing explicit instead of transporting it as an anonymous
 * array-shape through the reconciliation logic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExistingCompanionVideoCandidate
{
    /**
     * @param AssetGroup $group Group that already contains the valid still+video pair
     * @param AssetItem  $item  Existing companion video inside that group
     */
    public function __construct(
        public AssetGroup $group,
        public AssetItem $item,
    ) {
    }
}
