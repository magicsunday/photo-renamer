<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures\VirtualFlow;

use ArrayIterator;
use RecursiveIterator;
use SplFileInfo;

/**
 * Flat recursive iterator over a list of virtual SplFileInfo entries.
 *
 * The production command walks a recursive filesystem iterator, but the virtual
 * flow harness only needs to hand a deterministic list of in-memory file objects
 * to AssetGroupPipeline. This adapter gives the tests the same iterator shape
 * without requiring real directories or child iterators.
 *
 * @extends ArrayIterator<int, SplFileInfo>
 *
 * @implements RecursiveIterator<int, SplFileInfo>
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class FlatSplFileInfoRecursiveIterator extends ArrayIterator implements RecursiveIterator
{
    /**
     * The virtual iterator is flat, so no entry exposes children.
     */
    public function hasChildren(): bool
    {
        return false;
    }

    /**
     * The flat virtual iterator never exposes child iterators.
     *
     * Returning itself satisfies the RecursiveIterator contract while remaining
     * unreachable because hasChildren() is always false.
     *
     * @return RecursiveIterator<int, SplFileInfo>
     */
    public function getChildren(): RecursiveIterator
    {
        return $this;
    }
}
