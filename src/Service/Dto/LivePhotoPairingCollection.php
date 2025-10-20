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
 * Collection of Live Photo pairings.
 */
final class LivePhotoPairingCollection implements IteratorAggregate
{
    /**
     * @param list<LivePhotoPairing> $pairings
     */
    private function __construct(
        private array $pairings,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromPairings(LivePhotoPairing ...$pairings): self
    {
        return new self($pairings);
    }

    /**
     * @param list<LivePhotoPairing> $pairings
     */
    public static function fromList(array $pairings): self
    {
        return new self($pairings);
    }

    public function add(LivePhotoPairing $pairing): void
    {
        $this->pairings[] = $pairing;
    }

    /**
     * @return list<LivePhotoPairing>
     */
    public function toList(): array
    {
        return $this->pairings;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->pairings);
    }
}
