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
use SplFileInfo;

/**
 * @extends AbstractCollection<int, SplFileInfo>
 */
final class FileList extends AbstractCollection
{
    /**
     * @param SplFileInfo[] $array
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
        if (!($value instanceof SplFileInfo)) {
            throw new InvalidArgumentException('Value must be an instance of SplFileInfo.');
        }

        parent::append($value);
    }

    public function get(int|string $key): ?SplFileInfo
    {
        $value = parent::get($key);

        if ($value === null) {
            return null;
        }

        \assert($value instanceof SplFileInfo);

        return $value;
    }

    public function set(int|string $key, object $value): void
    {
        if (!($value instanceof SplFileInfo)) {
            throw new InvalidArgumentException('Value must be an instance of SplFileInfo.');
        }

        parent::set($key, $value);
    }
}
