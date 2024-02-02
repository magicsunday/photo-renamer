<?php

/**
 * This file is part of the package magicsunday/renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use SplFileInfo;

/**
 * The object holding info about the file renaming.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/renamer/
 */
class FileInfo
{
    /**
     * @var string
     */
    protected string $path = '';

    /**
     * @var string
     */
    protected string $targetFilename = '';

    /**
     * @var SplFileInfo[]
     */
    protected array $files = [];

    /**
     * @var array
     */
    protected array $duplicateFiles = [];

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param string $path
     *
     * @return FileInfo
     */
    public function setPath(string $path): FileInfo
    {
        $this->path = $path;
        return $this;
    }

    /**
     * @return SplFileInfo[]
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @param SplFileInfo[] $files
     *
     * @return FileInfo
     */
    public function setFiles(array $files): FileInfo
    {
        $this->files = $files;
        return $this;
    }

    /**
     * @param SplFileInfo $fileInfo
     *
     * @return FileInfo
     */
    public function addFile(SplFileInfo $fileInfo): FileInfo
    {
        $this->files[] = $fileInfo;
        return $this;
    }
}
