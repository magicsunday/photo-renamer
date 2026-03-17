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
 * Groups multiple source files that share the same duplicate identifier (e.g. same EXIF date
 * or same target basename). Holds both the raw source file list and the computed rename
 * operations, plus the canonical target file info used as the base name for the group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class FileDuplicate
{
    /**
     * Canonical target file info representing the base name assigned to this group.
     */
    private SplFileInfo $target;

    /**
     * @param FileList   $files   Source files belonging to this duplicate group
     * @param RenameList $renames Computed rename operations for all files in this group
     */
    public function __construct(private readonly FileList $files = new FileList(), private RenameList $renames = new RenameList())
    {
        $this->target = new SplFileInfo('');
    }

    /**
     * Returns all source files that belong to this duplicate group.
     */
    public function getFiles(): FileList
    {
        return $this->files;
    }

    /**
     * Registers a source file as belonging to this duplicate group.
     *
     * @param SplFileInfo $fileInfo Source file to add
     *
     * @return FileDuplicate Fluent interface
     */
    public function addFile(SplFileInfo $fileInfo): self
    {
        $this->files->append($fileInfo);

        return $this;
    }

    /**
     * Returns the canonical target file info that defines the base name for this group.
     */
    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    /**
     * Assigns the canonical target file info, which determines the base filename
     * all members of this group will share (before duplicate suffixes).
     *
     * @param SplFileInfo $target Canonical target to set
     *
     * @return FileDuplicate Fluent interface
     */
    public function setTarget(SplFileInfo $target): self
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Returns the list of computed rename operations for all files in this group.
     */
    public function getRenames(): RenameList
    {
        return $this->renames;
    }

    /**
     * Replaces the entire rename list, used when hash sub-grouping recomputes
     * rename targets with sub-group suffixes.
     *
     * @param RenameList $renames New rename list to assign
     *
     * @return FileDuplicate Fluent interface
     */
    public function setRenames(RenameList $renames): self
    {
        $this->renames = $renames;

        return $this;
    }

    /**
     * Appends a single rename operation to this group's rename list.
     *
     * @param Rename $rename Rename operation to add
     *
     * @return FileDuplicate Fluent interface
     */
    public function addRename(Rename $rename): self
    {
        $this->renames->append($rename);

        return $this;
    }
}
