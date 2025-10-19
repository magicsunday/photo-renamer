<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Collection;

use InvalidArgumentException;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\Rename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(RenameList::class)]
class RenameListTest extends TestCase
{
    #[Test]
    public function itStoresRenameInstances(): void
    {
        $list   = new RenameList();
        $rename = new Rename(new SplFileInfo(__FILE__), new SplFileInfo(__FILE__));

        $list->append($rename);

        self::assertSame([$rename], $list->asArray());
        self::assertSame($rename, $list->get(0));
    }

    #[Test]
    public function itRejectsNonRenameValues(): void
    {
        $list = new RenameList();

        $this->expectException(InvalidArgumentException::class);
        $list->append(new class {
        });
    }

    #[Test]
    public function itReindexesAfterRemoval(): void
    {
        $firstRename  = new Rename(new SplFileInfo(__FILE__), new SplFileInfo(__FILE__));
        $secondRename = new Rename(new SplFileInfo(__FILE__), new SplFileInfo(__FILE__));

        $list = new RenameList([$firstRename, $secondRename]);
        $list->remove(0);
        $list->reindex();

        self::assertSame($secondRename, $list->get(0));
    }
}
