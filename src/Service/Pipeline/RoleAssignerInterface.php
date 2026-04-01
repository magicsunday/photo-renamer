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
use MagicSunday\Renamer\Model\PipelineContext;

/**
 * Assigns roles (Canonical, Duplicate, Companion, Ambiguous) to items in each group.
 * Uses CanonicalScorer for selection and CompanionDetector for Live Photo pairing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface RoleAssignerInterface
{
    /**
     * Assign roles (Canonical, Duplicate, Companion, Ambiguous) to items in each group.
     * Uses CanonicalScorer for selection and CompanionDetector for Live Photo pairing.
     *
     * @param AssetGroupCollection $groups  Groups whose items will receive roles
     * @param PipelineContext      $context Mutable pipeline state for quality flag propagation
     */
    public function assign(
        AssetGroupCollection $groups,
        PipelineContext $context,
    ): void;
}
