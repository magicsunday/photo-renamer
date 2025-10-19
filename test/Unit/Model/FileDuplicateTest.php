<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(FileDuplicate::class)]
class FileDuplicateTest extends TestCase
{
    #[Test]
    public function itTracksFilesAndRenamesUsingCollections(): void
    {
        $fileDuplicate = new FileDuplicate();

        $source = new SplFileInfo(__FILE__);
        $target = new SplFileInfo(__FILE__);
        $rename = new Rename($source, $target);

        $fileDuplicate
            ->addFile($source)
            ->addRename($rename)
            ->setTarget($target);

        self::assertInstanceOf(FileList::class, $fileDuplicate->getFiles());
        self::assertSame($source, $fileDuplicate->getFiles()->get(0));

        self::assertInstanceOf(RenameList::class, $fileDuplicate->getRenames());
        self::assertSame($rename, $fileDuplicate->getRenames()->get(0));
    }

    #[Test]
    public function itAllowsReplacingRenameLists(): void
    {
        $fileDuplicate = new FileDuplicate();
        $renameList    = new RenameList([
            new Rename(new SplFileInfo(__FILE__), new SplFileInfo(__FILE__)),
        ]);

        $fileDuplicate->setRenames($renameList);

        self::assertSame($renameList, $fileDuplicate->getRenames());
    }
}
