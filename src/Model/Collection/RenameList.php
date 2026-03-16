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
 * @extends AbstractCollection<int, Rename>
 */
final class RenameList extends AbstractCollection
{
    /**
     * @param Rename[] $array
     */
    public function __construct(array $array = [])
    {
        parent::__construct();

        foreach ($array as $value) {
            $this->append($value);
        }
    }

    /**
     * @param Rename $value
     */
    #[Override]
    public function append(object $value): void
    {
        parent::append($value);
    }

    /**
     * @param int $key
     */
    #[Override]
    public function get(int|string $key): ?Rename
    {
        return parent::get((int) $key);
    }

    /**
     * @param int    $key
     * @param Rename $value
     */
    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((int) $key, $value);
    }

    public function reindex(): self
    {
        $this->elements = array_values($this->elements);

        return $this;
    }
}
