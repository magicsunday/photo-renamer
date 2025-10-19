<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Collection;

use InvalidArgumentException;
use MagicSunday\Renamer\Model\Collection\FileList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(FileList::class)]
class FileListTest extends TestCase
{
    #[Test]
    public function itStoresSplFileInfoInstances(): void
    {
        $list = new FileList();
        $file = new SplFileInfo(__FILE__);

        $list->append($file);

        self::assertSame([$file], $list->asArray());
        self::assertSame($file, $list->get(0));
    }

    #[Test]
    public function itRejectsNonSplFileInfoValues(): void
    {
        $list = new FileList();

        $this->expectException(InvalidArgumentException::class);
        $list->append(new class {
        });
    }
}
