<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Model\AssetItem;

/**
 * Immutable candidate representing a singleton orphan MOV group eligible for the
 * pre-subgroup reconciliation pass.
 *
 * The reconciler must remember both the owning group key and the orphan video
 * item when evaluating expensive perceptual matches, so this DTO replaces the
 * former `array{groupKey, item}` contract.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OrphanVideoCandidate
{
    /**
     * @param string    $groupKey Group key of the singleton orphan video group
     * @param AssetItem $item     Orphan video item to reconcile
     */
    public function __construct(
        public string $groupKey,
        public AssetItem $item,
    ) {
    }
}
