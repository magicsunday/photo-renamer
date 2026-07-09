<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use SplFileInfo;

/**
 * Represents a group of files that are logically considered duplicates or
 * related (e.g., sharing the same EXIF date or target basename).
 *
 * This model holds the collection of source files, the planned rename
 * operations, and the canonical target information that defines the base
 * filename for the entire group. It is used primarily in the legacy
 * execution and output phases.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class FileDuplicate
{
    /**
     * The canonical target file information, defining the base name assigned
     * to this entire group.
     */
    private SplFileInfo $target;

    /**
     * @param FileList   $files   The collection of source files belonging
     *                            to this duplicate group.
     * @param RenameList $renames The collection of computed rename operations
     *                            for all files in this group.
     */
    public function __construct(private readonly FileList $files = new FileList(), private RenameList $renames = new RenameList())
    {
        $this->target = new SplFileInfo('');
    }

    /**
     * Returns the collection of all source files belonging to this group.
     *
     * @return FileList The list of source files.
     */
    public function getFiles(): FileList
    {
        return $this->files;
    }

    /**
     * Registers a new source file as part of this duplicate group.
     *
     * @param SplFileInfo $fileInfo The source file to be added to the group.
     *
     * @return FileDuplicate Fluent interface for method chaining.
     */
    public function addFile(SplFileInfo $fileInfo): self
    {
        $this->files->append($fileInfo);

        return $this;
    }

    /**
     * Returns the canonical target file information for this group.
     *
     * @return SplFileInfo The target file info defining the base name.
     */
    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    /**
     * Sets the canonical target file information for this group.
     *
     * This target determines the common base filename that all members of
     * this group will share before any duplicate numbering suffixes are applied.
     *
     * @param SplFileInfo $target The canonical target information to assign.
     *
     * @return FileDuplicate Fluent interface for method chaining.
     */
    public function setTarget(SplFileInfo $target): self
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Returns the collection of all planned rename operations for this group.
     *
     * @return RenameList The list of rename operations.
     */
    public function getRenames(): RenameList
    {
        return $this->renames;
    }

    /**
     * Replaces the entire collection of rename operations.
     *
     * This is typically used when the grouping or naming logic needs to
     * re-evaluate the target paths, for example, after hash sub-grouping.
     *
     * @param RenameList $renames The new collection of rename operations.
     *
     * @return FileDuplicate Fluent interface for method chaining.
     */
    public function setRenames(RenameList $renames): self
    {
        $this->renames = $renames;

        return $this;
    }

    /**
     * Adds a single rename operation to the group's collection.
     *
     * @param Rename $rename The specific rename operation to add.
     *
     * @return FileDuplicate Fluent interface for method chaining.
     */
    public function addRename(Rename $rename): self
    {
        $this->renames->append($rename);

        return $this;
    }
}
