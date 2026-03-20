<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Override;

use function array_values;
use function count;

/**
 * Mutable, iterable collection of Live Photo pairings discovered during the
 * companion pairing phase. Created empty or from an initial list, then grown
 * via {@see add()} as new companions are matched.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @implements IteratorAggregate<int, LivePhotoPairing>
 */
final class LivePhotoPairingCollection implements Countable, IteratorAggregate
{
    /**
     * Builds the collection from an initial list of pairings.
     *
     * @param list<LivePhotoPairing> $pairings items that should be available from the start
     */
    private function __construct(
        private array $pairings,
    ) {
    }

    /**
     * Creates an empty collection.
     *
     * @return self collection without any pairings
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Creates a collection from the provided pairings.
     *
     * @param LivePhotoPairing ...$pairings Pairings that should populate the collection.
     *
     * @return self collection containing the provided pairings
     */
    public static function fromPairings(LivePhotoPairing ...$pairings): self
    {
        return new self(array_values($pairings));
    }

    /**
     * Appends a pairing to the collection.
     *
     * @param LivePhotoPairing $pairing pairing that should be tracked
     */
    public function add(LivePhotoPairing $pairing): void
    {
        $this->pairings[] = $pairing;
    }

    /**
     * Exposes the collection contents as a list.
     *
     * @return list<LivePhotoPairing> items currently stored in the collection
     */
    public function toList(): array
    {
        return $this->pairings;
    }

    /**
     * Returns the number of pairings in the collection.
     */
    #[Override]
    public function count(): int
    {
        return count($this->pairings);
    }

    /**
     * Creates an iterator for the collection.
     *
     * @return ArrayIterator<int, LivePhotoPairing> iterator over the contained pairings
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->pairings);
    }
}
