<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Collection;

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
