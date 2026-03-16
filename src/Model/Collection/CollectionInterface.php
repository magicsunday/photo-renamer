<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Contract for typed, countable and iterable object collections with
 * key-based access, mutation and append semantics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @template TKey of array-key
 * @template TValue of object
 *
 * @extends IteratorAggregate<TKey, TValue>
 */
interface CollectionInterface extends Countable, IteratorAggregate
{
    /**
     * Retrieves an element from the collection by key.
     *
     * @param TKey $key The key of the element to retrieve
     *
     * @return TValue|null The element if found, null otherwise
     */
    public function get(int|string $key): ?object;

    /**
     * Stores an element in the collection at the given key.
     *
     * @param TKey   $key   The key to assign the value to
     * @param TValue $value The value to store
     */
    public function set(int|string $key, object $value): void;

    /**
     * Checks if the collection contains the given key.
     *
     * @param TKey $key The key to check for
     */
    public function has(int|string $key): bool;

    /**
     * Removes an element from the collection.
     *
     * @param TKey $key The key to remove
     */
    public function remove(int|string $key): void;

    /**
     * Appends a value to the end of the collection.
     *
     * @param TValue $value The value to append
     */
    public function append(object $value): void;

    /**
     * Returns the collection as a plain array.
     *
     * @return array<TKey, TValue>
     */
    public function asArray(): array;

    /**
     * @return Traversable<TKey, TValue>
     */
    public function getIterator(): Traversable;
}
