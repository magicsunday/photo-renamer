<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Collection;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the key-value storage contract of FileDuplicateCollection.
 *
 * FileDuplicateCollection is the top-level container that maps duplicate-group
 * identifiers (e.g. "live-photo:content-id") to their FileDuplicate instances.
 * These tests guarantee that entries can be stored, retrieved by key, and
 * appended without key, which is essential for every downstream consumer
 * of the grouping pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(FileDuplicateCollection::class)]
final class FileDuplicateCollectionTest extends TestCase
{
    /**
     * Verifies that a FileDuplicate stored under a specific key can be retrieved
     * by that same key, and that has() correctly reports its presence.
     *
     * This is the fundamental read-after-write contract: the duplicate detection
     * service relies on set()/get()/has() to build and inspect the collection
     * during grouping. A failure here would break the entire rename pipeline.
     */
    #[Test]
    public function itStoresAndRetrievesDuplicatesByKey(): void
    {
        $collection = new FileDuplicateCollection();
        $duplicate  = new FileDuplicate();
        $duplicate->setTarget(new SplFileInfo(__FILE__));

        $collection->set('foo', $duplicate);

        self::assertTrue($collection->has('foo'));
        self::assertSame($duplicate, $collection->get('foo'));
    }

    /**
     * Verifies that append() adds a FileDuplicate without requiring an explicit key,
     * and that the entry is accessible via asArray() afterward.
     *
     * The append path is used when the caller does not need keyed lookup but simply
     * collects duplicates sequentially. The test ensures the collection is not empty
     * after appending and that the identical object instance is preserved.
     */
    #[Test]
    public function itAppendsValues(): void
    {
        $collection = new FileDuplicateCollection();
        $duplicate  = new FileDuplicate();
        $duplicate->setTarget(new SplFileInfo(__FILE__));

        $collection->append($duplicate);

        $items = $collection->asArray();
        self::assertCount(1, $items);
        self::assertSame($duplicate, reset($items));
    }
}
