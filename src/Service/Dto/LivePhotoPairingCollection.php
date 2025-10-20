<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Mutable collection managing matched Live Photo pairings.
 */
final class LivePhotoPairingCollection implements IteratorAggregate
{
    /**
     * Builds the collection from an initial list of pairings.
     *
     * @param list<LivePhotoPairing> $pairings Items that should be available from the start.
     */
    private function __construct(
        private array $pairings,
    ) {
    }

    /**
     * Creates an empty collection.
     *
     * @return self Collection without any pairings.
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
     * @return self Collection containing the provided pairings.
     */
    public static function fromPairings(LivePhotoPairing ...$pairings): self
    {
        return new self($pairings);
    }

    /**
     * Creates a collection from an existing list of pairings.
     *
     * @param list<LivePhotoPairing> $pairings Items to seed the collection with.
     *
     * @return self Collection containing the supplied pairings.
     */
    public static function fromList(array $pairings): self
    {
        return new self($pairings);
    }

    /**
     * Appends a pairing to the collection.
     *
     * @param LivePhotoPairing $pairing Pairing that should be tracked.
     *
     * @return void
     */
    public function add(LivePhotoPairing $pairing): void
    {
        $this->pairings[] = $pairing;
    }

    /**
     * Exposes the collection contents as a list.
     *
     * @return list<LivePhotoPairing> Items currently stored in the collection.
     */
    public function toList(): array
    {
        return $this->pairings;
    }

    /**
     * Creates an iterator for the collection.
     *
     * @return Traversable Iterator over the contained pairings.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->pairings);
    }
}
