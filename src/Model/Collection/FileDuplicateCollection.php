<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use MagicSunday\Renamer\Model\FileDuplicate;
use Override;

/**
 * A file duplicate collection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @extends AbstractCollection<string, FileDuplicate>
 */
class FileDuplicateCollection extends AbstractCollection
{
    /**
     * @param FileDuplicate $value
     */
    #[Override]
    public function append(object $value): void
    {
        parent::append($value);
    }

    /**
     * @param string        $key
     * @param FileDuplicate $value
     */
    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((string) $key, $value);
    }

    /**
     * @param string $key
     */
    #[Override]
    public function get(int|string $key): ?FileDuplicate
    {
        return parent::get((string) $key);
    }
}
