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
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the typed-list contract of RenameList, the ordered collection of
 * source-to-target Rename pairs assigned to a single duplicate group.
 *
 * RenameList is the final output of createDuplicateFilenames() and is consumed
 * by FileSystemService::renameFiles() to execute the actual file operations.
 * Correct ordering, removal, and re-indexing are essential for the rename pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameList::class)]
#[UsesClass(Rename::class)]
final class RenameListTest extends TestCase
{
    /**
     * Verifies that a Rename instance appended to the list can be retrieved by its
     * zero-based index and that asArray() returns the complete contents.
     *
     * This is the basic storage contract used when createDuplicateFilenames()
     * builds up the rename plan one entry at a time.
     */
    #[Test]
    public function itStoresRenameInstances(): void
    {
        $list   = new RenameList();
        $rename = new Rename(new SplFileInfo(__FILE__), new SplFileInfo(__FILE__));

        $list->append($rename);

        self::assertSame([$rename], $list->asArray());
        self::assertSame($rename, $list->get(0));
    }

    /**
     * Verifies that removing an entry and calling reindex() collapses the list
     * so that subsequent get(0) returns the formerly second element.
     *
     * Re-indexing is used after hash sub-grouping replaces the rename list
     * with a filtered subset. Without correct re-indexing, consumers iterating
     * by numeric index would encounter gaps or null entries.
     */
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
