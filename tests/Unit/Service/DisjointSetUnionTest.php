<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\DisjointSetUnion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the disjoint-set union helper used for perceptual hash grouping.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DisjointSetUnion::class)]
final class DisjointSetUnionTest extends TestCase
{
    /**
     * Verifies that find() performs path halving on nested parent chains.
     */
    #[Test]
    public function findPerformsPathHalvingOnNestedChains(): void
    {
        $components = new DisjointSetUnion(4);
        $components->union(1, 0);
        $components->union(2, 1);
        $components->union(3, 2);

        self::assertSame(3, $components->find(3));
        self::assertSame([0 => 1, 1 => 2, 2 => 3, 3 => 3], $components->parents());

        self::assertSame(3, $components->find(0));
        self::assertSame([0 => 2, 1 => 2, 2 => 3, 3 => 3], $components->parents());
    }

    /**
     * Verifies that union() connects roots without changing already connected sets.
     */
    #[Test]
    public function unionConnectsDistinctComponents(): void
    {
        $components = new DisjointSetUnion(3);

        $components->union(0, 1);
        $components->union(1, 2);
        $components->union(0, 2);

        self::assertSame(0, $components->find(2));
        self::assertSame([0 => 0, 1 => 0, 2 => 0], $components->parents());
    }
}
