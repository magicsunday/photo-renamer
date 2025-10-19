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
use MagicSunday\Renamer\Model\FileDuplicate;

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
    public function append(object $value): void
    {
        $this->assertInstance($value);

        parent::append($value);
    }

    public function set(int|string $key, object $value): void
    {
        $this->assertInstance($value);

        parent::set($key, $value);
    }

    public function get(int|string $key): ?FileDuplicate
    {
        $value = parent::get($key);

        if ($value instanceof FileDuplicate) {
            return $value;
        }

        return null;
    }

    /**
     * @param object $value
     */
    private function assertInstance(object $value): void
    {
        if (!($value instanceof FileDuplicate)) {
            throw new InvalidArgumentException('Value must be an instance of FileDuplicate.');
        }
    }
}
