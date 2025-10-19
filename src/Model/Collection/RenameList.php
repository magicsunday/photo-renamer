<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use InvalidArgumentException;
use MagicSunday\Renamer\Model\Rename;

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

    public function append(object $value): void
    {
        if (!($value instanceof Rename)) {
            throw new InvalidArgumentException('Value must be an instance of Rename.');
        }

        parent::append($value);
    }

    public function get(int|string $key): ?Rename
    {
        $value = parent::get($key);

        if ($value === null) {
            return null;
        }

        \assert($value instanceof Rename);

        return $value;
    }

    public function set(int|string $key, object $value): void
    {
        if (!($value instanceof Rename)) {
            throw new InvalidArgumentException('Value must be an instance of Rename.');
        }

        parent::set($key, $value);
    }

    public function reindex(): self
    {
        $this->elements = array_values($this->elements);

        return $this;
    }
}
