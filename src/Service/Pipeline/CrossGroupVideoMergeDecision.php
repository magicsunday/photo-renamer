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
 * Immutable merge-direction decision for an exact duplicate cross-group video.
 *
 * Once a stream-level exact duplicate is confirmed, the reconciler still needs a
 * stable answer for which group/item pair becomes the anchor and which item is
 * moved. This DTO keeps that target/source decision explicit instead of returning
 * a positional four-element tuple.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CrossGroupVideoMergeDecision
{
    /**
     * @param AssetGroup $targetGroup Anchor group that keeps the canonical position
     * @param AssetItem  $targetItem  Existing anchor video item inside the target group
     * @param AssetGroup $sourceGroup Group that will lose the moved duplicate item
     * @param AssetItem  $sourceItem  Duplicate video item to move into the anchor group
     */
    public function __construct(
        public AssetGroup $targetGroup,
        public AssetItem $targetItem,
        public AssetGroup $sourceGroup,
        public AssetItem $sourceItem,
    ) {
    }
}
