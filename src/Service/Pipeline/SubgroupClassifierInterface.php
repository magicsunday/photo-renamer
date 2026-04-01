<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;

/**
 * Classifies items within each AssetGroup into content-identity sub-groups (clusters).
 *
 * Sets clusterId on each affected AssetItem via perceptual and content-hash analysis.
 * Does NOT assign roles or compute target names — those are separate pipeline phases.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface SubgroupClassifierInterface
{
    /**
     * Classify items within each group by content identity / perceptual similarity.
     * Sets clusterId on each affected AssetItem.
     * Does NOT assign roles or compute names.
     *
     * @param AssetGroupCollection $groups The groups to classify
     */
    public function classify(
        AssetGroupCollection $groups,
    ): void;
}
