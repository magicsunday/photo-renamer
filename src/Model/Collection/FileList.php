<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use Override;
use SplFileInfo;

/**
 * Integer-indexed collection of SplFileInfo objects representing source files
 * discovered during directory scanning or grouped by a duplicate identifier.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @extends AbstractCollection<int, SplFileInfo>
 */
final class FileList extends AbstractCollection
{
    /**
     * @param SplFileInfo[] $array Initial list of SplFileInfo objects.
     *                             These are appended to the collection using
     *                             sequential integer keys.
     */
    public function __construct(array $array = [])
    {
        parent::__construct();

        foreach ($array as $value) {
            $this->append($value);
        }
    }

    /**
     * Retrieves a file info object by its index.
     *
     * @param int|string $key The numeric index (cast to int).
     *
     * @return SplFileInfo|null The file info object if found, or null otherwise.
     */
    #[Override]
    public function get(int|string $key): ?SplFileInfo
    {
        return parent::get((int) $key);
    }

    /**
     * Stores a file info object at the specified index.
     *
     * @param int|string  $key   The numeric index (cast to int).
     * @param SplFileInfo $value The file info object to store.
     */
    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((int) $key, $value);
    }
}
