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
 * Reconciles exact-content video duplicates that were split across capture groups.
 *
 * The interface exists so AssetGroupPipeline can depend on a narrow behavior
 * boundary and tests can mock the regrouping step without invoking real video
 * fingerprinting.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface CrossGroupVideoDuplicateReconcilerInterface
{
    /**
     * Reconciles cross-group video duplicates before subgroup classification starts.
     *
     * @param AssetGroupCollection $groups  Capture groups built from metadata-driven grouping
     * @param PipelineContext      $context Mutable pipeline context receiving review findings
     */
    public function reconcile(AssetGroupCollection $groups, PipelineContext $context): void;
}
