<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

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
    /**
     * @var SplFileInfo[]
     */
    private array $files = [];

    /**
     * @var SplFileInfo
     */
    private SplFileInfo $target;

    /**
     * @var Rename[]
     */
    private array $renames = [];

    /**
     * @return SplFileInfo[]
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @param SplFileInfo $fileInfo
     *
     * @return FileDuplicate
     */
    public function addFile(SplFileInfo $fileInfo): FileDuplicate
    {
        $this->files[] = $fileInfo;

        return $this;
    }

    /**
     * @return SplFileInfo
     */
    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    /**
     * @param SplFileInfo $target
     *
     * @return FileDuplicate
     */
    public function setTarget(SplFileInfo $target): FileDuplicate
    {
        $this->target = $target;

        return $this;
    }

    /**
     * @return Rename[]
     */
    public function getRenames(): array
    {
        return $this->renames;
    }

    /**
     * @param Rename[] $renames
     *
     * @return FileDuplicate
     */
    public function setRenames(array $renames): FileDuplicate
    {
        $this->renames = $renames;

        return $this;
    }

    /**
     * @param Rename $rename
     *
     * @return FileDuplicate
     */
    public function addRename(Rename $rename): FileDuplicate
    {
        $this->renames[] = $rename;

        return $this;
    }
}
