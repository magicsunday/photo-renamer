<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

/**
 * An integer-indexed collection that can grow by appending.
 *
 * Appending lets PHP choose the next integer key, so it is only sound for a
 * collection whose keys are integers. Keeping append() here rather than on
 * AbstractCollection means a string-keyed collection (duplicate groups keyed
 * by identifier, asset groups keyed by group key) cannot receive an integer key
 * by accident and break its own key invariant.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @template TValue of object
 *
 * @extends AbstractCollection<int, TValue>
 */
abstract class AbstractList extends AbstractCollection
{
    /**
     * Appends an element to the end of the collection using an auto-incremented key.
     *
     * This is useful for simple lists where the specific key is either
     * irrelevant or not yet determined.
     *
     * @param TValue $value The element to append.
     */
    public function append(object $value): void
    {
        $this->elements[] = $value;
    }
}
