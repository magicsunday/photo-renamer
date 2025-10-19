<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Collection;

use InvalidArgumentException;
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

        self::assertSame([$duplicate], $collection->asArray());
    }

    #[Test]
    public function itRejectsNonFileDuplicateValues(): void
    {
        $collection = new FileDuplicateCollection();

        $this->expectException(InvalidArgumentException::class);
        $collection->append(new class {
        });
    }
}
