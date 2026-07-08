<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Filesystem;

use MagicSunday\Renamer\Service\Filesystem\SortedFileIteratorCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Verifies portable file iterator collection and sorting.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(SortedFileIteratorCollector::class)]
final class SortedFileIteratorCollectorTest extends TestCase
{
    /**
     * Verifies that Windows-style paths are sorted by directory depth on non-Windows runtimes.
     */
    #[Test]
    public function collectAndSortFilesUsesPortableSeparatorDepth(): void
    {
        $iterator = new RecursiveIteratorIterator(new SortedFileIteratorCollectorTestIterator([
            new SplFileInfo('C:\\Photos\\nested\\b.jpg'),
            new SplFileInfo('C:\\Photos\\a.jpg'),
            new SplFileInfo('C:\\Photos\\nested\\deeper\\c.jpg'),
        ]));

        $files = SortedFileIteratorCollector::collectAndSortFiles($iterator);

        self::assertSame([
            'C:\\Photos\\a.jpg',
            'C:\\Photos\\nested\\b.jpg',
            'C:\\Photos\\nested\\deeper\\c.jpg',
        ], [
            $files[0]->getPathname(),
            $files[1]->getPathname(),
            $files[2]->getPathname(),
        ]);
    }
}

/**
 * @implements RecursiveIterator<int, SplFileInfo>
 */
final class SortedFileIteratorCollectorTestIterator implements RecursiveIterator
{
    /**
     * @param list<SplFileInfo> $files
     */
    public function __construct(private array $files, private int $position = 0)
    {
    }

    public function current(): SplFileInfo
    {
        return $this->files[$this->position];
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): int
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->files[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function hasChildren(): bool
    {
        return false;
    }

    /**
     * @return RecursiveIterator<int, SplFileInfo>|null
     */
    public function getChildren(): ?RecursiveIterator
    {
        return null;
    }
}
