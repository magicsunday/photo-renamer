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

    /**
     * @param SplFileInfo $value
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
    public function get(int|string $key): ?SplFileInfo
    {
        return parent::get((int) $key);
    }

    /**
     * @param int         $key
     * @param SplFileInfo $value
     */
    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((int) $key, $value);
    }
}
