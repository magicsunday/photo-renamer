<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the aggregation contract of FileDuplicate, the central model that
 * links source files (FileList), a canonical target (SplFileInfo), and the
 * resulting rename pairs (RenameList) for a single duplicate group.
 *
 * Every stage of the rename pipeline reads from or writes to FileDuplicate:
 * grouping populates files and target, createDuplicateFilenames() populates
 * renames, and renameFiles() executes them. Correct mutation and retrieval
 * is therefore essential for the entire workflow.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(FileDuplicate::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(Rename::class)]
final class FileDuplicateTest extends TestCase
{
    /**
     * Verifies that addFile(), addRename(), and setTarget() correctly populate
     * their respective collections and that the stored objects are retrievable
     * as the identical instances via getFiles() and getRenames().
     *
     * This covers the primary write path used by groupFilesByDuplicateIdentifier()
     * and createDuplicateFilenames(), where files and renames are added one by one.
     */
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

        self::assertSame($source, $fileDuplicate->getFiles()->get(0));
        self::assertSame($rename, $fileDuplicate->getRenames()->get(0));
    }

    /**
     * Verifies that setRenames() replaces the entire RenameList in one call and
     * that getRenames() returns the replacement instance.
     *
     * Hash sub-grouping uses setRenames() to swap the preliminary rename list
     * with the re-ordered, sub-grouped version. This test ensures the bulk
     * replacement does not silently merge with or discard the new list.
     */
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
