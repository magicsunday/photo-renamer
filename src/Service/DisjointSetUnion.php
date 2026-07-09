<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

/**
 * Disjoint-set union structure with path halving.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class DisjointSetUnion
{
    /**
     * @var array<int, int>
     */
    private array $parent = [];

    public function __construct(int $size)
    {
        for ($index = 0; $index < $size; ++$index) {
            $this->parent[$index] = $index;
        }
    }

    public function find(int $index): int
    {
        while ($this->parent[$index] !== $index) {
            $this->parent[$index] = $this->parent[$this->parent[$index]];
            $index                = $this->parent[$index];
        }

        return $index;
    }

    public function union(int $indexA, int $indexB): void
    {
        $rootA = $this->find($indexA);
        $rootB = $this->find($indexB);

        if ($rootA !== $rootB) {
            $this->parent[$rootB] = $rootA;
        }
    }

    /**
     * @return array<int, int>
     */
    public function parents(): array
    {
        return $this->parent;
    }
}
