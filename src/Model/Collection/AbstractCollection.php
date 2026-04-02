<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Override;
use Traversable;

use function array_key_exists;
use function count;

/**
 * A generic array-backed collection providing iteration and basic management.
 *
 * This abstract class serves as the foundation for all specialized collections
 * in the application. It provides common functionality for adding, retrieving,
 * and iterating over elements while maintaining their insertion order.
 * Concrete subclasses use PHPStan templates to enforce type safety for keys
 * and values.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @template TKey of array-key
 * @template TValue of object
 *
 * @implements IteratorAggregate<TKey, TValue>
 */
abstract class AbstractCollection implements Countable, IteratorAggregate
{
    /**
     * @param array<TKey, TValue> $elements Initial elements to populate the collection.
     */
    public function __construct(
        /**
         * The internal storage holding all elements. Elements are indexed
         * by their key to allow O(1) lookups.
         *
         * @var array<TKey, TValue>
         */
        protected array $elements = [],
    ) {
    }

    /**
     * Returns the total number of elements currently in the collection.
     *
     * @return int<0, max> The number of elements.
     */
    #[Override]
    public function count(): int
    {
        return count($this->elements);
    }

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

    /**
     * Returns the internal elements as a plain PHP array.
     *
     * @return array<TKey, TValue> The underlying array storage.
     */
    public function asArray(): array
    {
        return $this->elements;
    }

    /**
     * Retrieves an element by its key.
     *
     * @param TKey $key The key to look up (either an integer or a string).
     *
     * @return TValue|null The element if it exists, or null otherwise.
     */
    public function get(int|string $key): ?object
    {
        return $this->elements[$key] ?? null;
    }

    /**
     * Stores an element under the specified key.
     *
     * If an element already exists at the given key, it will be overwritten.
     *
     * @param TKey   $key   The key under which the value should be stored.
     * @param TValue $value The element to store.
     */
    public function set(int|string $key, object $value): void
    {
        $this->elements[$key] = $value;
    }

    /**
     * Checks if an element with the given key exists in the collection.
     *
     * @param TKey $key The key to check.
     *
     * @return bool True if the key exists, false otherwise.
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->elements);
    }

    /**
     * Removes the element associated with the given key.
     *
     * If the key does not exist, this operation does nothing.
     *
     * @param TKey $key The key of the element to remove.
     */
    public function remove(int|string $key): void
    {
        unset($this->elements[$key]);
    }

    /**
     * Returns an iterator to allow looping over the collection.
     *
     * @return Traversable<TKey, TValue> An iterator for the collection elements.
     */
    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }
}
