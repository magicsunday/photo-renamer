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
 * Detects Live Photo companions for a given canonical item within an asset group.
 *
 * Uses content-ID matching (highest priority) and basename fallback (when content ID
 * is absent on the candidate) with safety guards against ambiguous and conflicting pairs.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface CompanionDetectorInterface
{
    /**
     * Detect Live Photo companions for the given canonical item within the group.
     *
     * @param AssetGroup $group     Group containing candidate companion items
     * @param AssetItem  $canonical Canonical item whose companions are sought
     *
     * @return CompanionPathSet Pathnames of detected companion items
     */
    public function detect(AssetGroup $group, AssetItem $canonical): CompanionPathSet;
}
