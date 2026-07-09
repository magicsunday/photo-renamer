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
 * A string-keyed collection of {@see AssetGroup} instances.
 *
 * This collection stores asset groups produced during the grouping phase of
 * the renaming pipeline. Each key typically represents a stable logical
 * identifier, such as a formatted capture timestamp or a content identifier,
 * which uniquely identifies the capture event.
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
     * Retrieves the {@see AssetGroup} associated with the given key.
     *
     * This provides a type-safe way to access a specific group from the
     * collection. The key is automatically cast to a string to ensure
     * compatibility with the underlying storage.
     *
     * @param int|string $key The group key to look up (e.g., a timestamp string).
     *
     * @return AssetGroup|null The associated AssetGroup if found, or null if
     *                         no group exists for the given key.
     */
    #[Override]
    public function get(int|string $key): ?AssetGroup
    {
        return parent::get((string) $key);
    }
}
