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
use Traversable;

use function array_filter;
use function array_key_exists;
use function array_slice;
use function count;
use function uasort;

/**
 * Generic array-backed collection providing iteration, filtering, sorting and
 * slice operations. Concrete subclasses narrow the key and value types via
 * template specialization.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @template TKey of array-key
 * @template TValue of object
 *
 * @implements CollectionInterface<TKey, TValue>
 */
abstract class AbstractCollection implements CollectionInterface
{
    /**
     * @param array<TKey, TValue> $elements Initial elements to populate the collection
     */
    public function __construct(
        /**
         * Internal storage holding all elements indexed by their key.
         *
         * @var array<TKey, TValue>
         */
        protected array $elements = [],
    ) {
    }

    /**
     * Returns the number of elements currently stored in the collection.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->elements);
    }

    /**
     * Appends an element to the end of the collection using an auto-incremented integer key.
     *
     * @param TValue $value The element to append
     */
    public function append(object $value): void
    {
        $this->elements[] = $value;
    }

    /**
     * Returns the collection as a plain array.
     *
     * @return array<TKey, TValue>
     */
    public function asArray(): array
    {
        return $this->elements;
    }

    /**
     * Sorts elements in-place using the provided comparison function while preserving keys.
     *
     * @param callable $callback Comparison function accepting two elements, returning int
     *
     * @return self<TKey, TValue>
     */
    public function sort(callable $callback): self
    {
        uasort($this->elements, $callback);

        return $this;
    }

    /**
     * Removes elements that do not satisfy the predicate. Mutates the internal
     * array and preserves original keys (gaps may appear in numeric sequences).
     *
     * @param callable $callback Predicate returning true for elements to keep
     *
     * @return self<TKey, TValue>
     */
    public function filter(callable $callback): self
    {
        $this->elements = array_filter($this->elements, $callback);

        return $this;
    }

    /**
     * Extract a slice of the array.
     *
     * @param int      $offset If the offset is non-negative, the sequence will start at that offset in the array. If
     *                         offset is negative, the sequence will start that far from the end of the array.
     * @param int|null $length If length is given and is positive, then the sequence will have that many elements
     *                         in it. If length is given and is negative, then the sequence will stop that many
     *                         elements from the end of the array. If it is omitted, then the sequence will have
     *                         everything from offset up until the end of the array.
     *
     * @return self<TKey, TValue>
     */
    public function slice(int $offset, ?int $length = null): self
    {
        $this->elements = array_slice($this->elements, $offset, $length);

        return $this;
    }

    /**
     * Retrieves an element by its key, returning null when the key does not exist.
     *
     * @param TKey $key
     *
     * @return TValue|null
     */
    public function get(int|string $key): ?object
    {
        return $this->elements[$key] ?? null;
    }

    /**
     * Stores an element at the specified key, overwriting any previous value at that position.
     *
     * @param TKey   $key
     * @param TValue $value
     */
    public function set(int|string $key, object $value): void
    {
        $this->elements[$key] = $value;
    }

    /**
     * Checks whether an element with the given key exists in the collection.
     *
     * @param TKey $key
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->elements);
    }

    /**
     * Removes the element at the given key. No-op if the key does not exist.
     *
     * @param TKey $key
     */
    public function remove(int|string $key): void
    {
        unset($this->elements[$key]);
    }

    /**
     * Returns an iterator over all elements in insertion order.
     *
     * @return Traversable<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }
}
