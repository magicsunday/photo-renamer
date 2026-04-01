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
 * Makes all proposed names unique against the disk index and already-planned
 * targets. Marks every resolved target as occupied in the context.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface CollisionResolverInterface
{
    /**
     * Make all proposed names unique against the disk index and already-planned targets.
     * Marks every resolved target as occupied in the context.
     *
     * @param AssetGroupCollection $groups  Groups with proposed names to deduplicate
     * @param PipelineContext      $context Pipeline state tracking occupied paths and collision count
     */
    public function resolve(
        AssetGroupCollection $groups,
        PipelineContext $context,
    ): void;
}
