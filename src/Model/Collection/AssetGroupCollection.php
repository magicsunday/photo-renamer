<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use MagicSunday\Renamer\Model\AssetGroup;
use Override;

/**
 * String-keyed collection of AssetGroup instances. Keys are the stable logical
 * group keys produced by the grouping phase (e.g. a datetime string or content
 * identifier), mapping each key to its AssetGroup.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @extends AbstractCollection<string, AssetGroup>
 */
final class AssetGroupCollection extends AbstractCollection
{
    /**
     * Returns the AssetGroup for the given key, or null if it does not exist.
     *
     * @param int|string $key Group key to look up (cast to string)
     */
    #[Override]
    public function get(int|string $key): ?AssetGroup
    {
        return parent::get((string) $key);
    }
}
