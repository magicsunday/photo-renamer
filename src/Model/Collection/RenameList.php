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
     * @param Rename[] $array Initial rename operations to populate the list
     */
    public function __construct(array $array = [])
    {
        parent::__construct();

        foreach ($array as $value) {
            $this->append($value);
        }
    }

    #[Override]
    public function append(object $value): void
    {
        parent::append($value);
    }

    #[Override]
    public function get(int|string $key): ?Rename
    {
        return parent::get((int) $key);
    }

    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((int) $key, $value);
    }

    /**
     * Re-numbers all keys to a contiguous zero-based integer sequence.
     * Call after filter() to eliminate gaps left by removed elements.
     *
     * @return self Fluent interface
     */
    public function reindex(): self
    {
        $this->elements = array_values($this->elements);

        return $this;
    }
}
