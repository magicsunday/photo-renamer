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
 * An abstract collection of values.
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
     * Constructs a list of values.
     *
     * @param array<TKey, TValue> $elements Array of values
     */
    public function __construct(
        /**
         * An array containing the elements of this collection.
         */
        protected array $elements = [],
    ) {
    }

    /**
     * Count elements of an object.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->elements);
    }

    /**
     * Appends a value to the collection.
     *
     * @param TValue $value The value to append
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
     * Sort the elements using a callback function.
     *
     * @param callable $callback The callback function to use
     *
     * @return self<TKey, TValue>
     */
    public function sort(callable $callback): self
    {
        uasort($this->elements, $callback);

        return $this;
    }

    /**
     * Filters the elements using a callback function.
     *
     * @param callable $callback The callback function to use
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
     * @param TKey $key
     *
     * @return TValue|null
     */
    public function get(int|string $key): ?object
    {
        return $this->elements[$key] ?? null;
    }

    /**
     * @param TKey   $key
     * @param TValue $value
     */
    public function set(int|string $key, object $value): void
    {
        $this->elements[$key] = $value;
    }

    /**
     * @param TKey $key
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->elements);
    }

    /**
     * @param TKey $key
     */
    public function remove(int|string $key): void
    {
        unset($this->elements[$key]);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }
}
