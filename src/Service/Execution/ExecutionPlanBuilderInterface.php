<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Execution;

use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\PipelineContext;

/**
 * Projects an AssetGroupCollection into an ExecutionPlan for the runtime
 * execution phase. Pure projection — no new business logic, no re-detection,
 * no collision resolution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface ExecutionPlanBuilderInterface
{
    /**
     * Projects an AssetGroupCollection into an ExecutionPlan.
     *
     * This is a pure projection with no new business logic, re-detection, or
     * collision resolution. It prepares the data for the final execution phase.
     *
     * @param AssetGroupCollection $groups  The analysed asset groups to project.
     * @param PipelineContext      $context The pipeline state containing quality flags.
     *
     * @return ExecutionPlan The resulting execution plan.
     */
    public function build(
        AssetGroupCollection $groups,
        PipelineContext $context,
    ): ExecutionPlan;
}
