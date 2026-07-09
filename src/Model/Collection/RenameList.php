<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use MagicSunday\Renamer\Model\Rename;
use Override;

use function array_values;

/**
 * Integer-indexed collection of Rename operations. Maintains the ordered list
 * of source-to-target mappings for a duplicate group, supporting reindexing
 * after filter operations remove gaps in the numeric key sequence.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @extends AbstractCollection<int, Rename>
 */
final class RenameList extends AbstractCollection
{
    /**
     * @param Rename[] $array Initial list of rename operations.
     *                        These are appended to the collection using
     *                        sequential integer keys.
     */
    public function __construct(array $array = [])
    {
        parent::__construct();

        foreach ($array as $value) {
            $this->append($value);
        }
    }

    /**
     * Retrieves a rename operation by its index.
     *
     * @param int|string $key The numeric index (cast to int).
     *
     * @return Rename|null The rename operation if found, or null otherwise.
     */
    #[Override]
    public function get(int|string $key): ?Rename
    {
        return parent::get((int) $key);
    }

    /**
     * Stores a rename operation at the specified index.
     *
     * @param int|string $key   The numeric index (cast to int).
     * @param Rename     $value The rename operation to store.
     */
    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((int) $key, $value);
    }

    /**
     * Re-numbers all keys to a contiguous zero-based integer sequence.
     * Call after manual removals or filter operations to eliminate gaps
     * left by removed elements, ensuring consistent iteration behavior.
     *
     * @return RenameList Returns the collection itself for chaining.
     */
    public function reindex(): self
    {
        $this->elements = array_values($this->elements);

        return $this;
    }
}
