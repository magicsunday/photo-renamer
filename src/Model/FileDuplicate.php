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
 * The object holding info about the file renaming.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class FileDuplicate
{
    private SplFileInfo $target;

    public function __construct(private readonly FileList $files = new FileList(), private RenameList $renames = new RenameList())
    {
    }

    public function getFiles(): FileList
    {
        return $this->files;
    }

    public function addFile(SplFileInfo $fileInfo): self
    {
        $this->files->append($fileInfo);

        return $this;
    }

    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    public function setTarget(SplFileInfo $target): FileDuplicate
    {
        $this->target = $target;

        return $this;
    }

    public function getRenames(): RenameList
    {
        return $this->renames;
    }

    public function setRenames(RenameList $renames): self
    {
        $this->renames = $renames;

        return $this;
    }

    public function addRename(Rename $rename): self
    {
        $this->renames->append($rename);

        return $this;
    }
}
