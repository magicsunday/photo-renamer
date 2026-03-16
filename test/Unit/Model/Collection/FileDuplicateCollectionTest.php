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

#[CoversClass(FileDuplicateCollection::class)]
class FileDuplicateCollectionTest extends TestCase
{
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
