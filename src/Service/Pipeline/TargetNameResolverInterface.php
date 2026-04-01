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
 * Computes desired target names for all items in all groups based on their role
 * and group key. Does NOT check for collisions -- that is CollisionResolver's job.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface TargetNameResolverInterface
{
    /**
     * Compute desired target names for all items in all groups.
     * Does NOT check for collisions — that is CollisionResolver's job.
     *
     * @param AssetGroupCollection $groups                     Groups with role-assigned items
     * @param bool                 $useFileExtensionFromSource When true, preserve source extension in target
     */
    public function resolve(
        AssetGroupCollection $groups,
        bool $useFileExtensionFromSource = false,
    ): void;
}
