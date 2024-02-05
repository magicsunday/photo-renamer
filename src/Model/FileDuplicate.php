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
     * @var string
     */
    protected string $path = '';

    /**
     * @var string
     */
    protected string $basename = '';

    /**
     * @var string
     */
    protected string $extension = '';

    /**
     * @var SplFileInfo[]
     */
    protected array $files = [];

    /**
     * @var string[]
     */
    protected array $new = [];

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
     * @return FileDuplicate
     */
    public function setPath(string $path): FileDuplicate
    {
        $this->path = $path;

        return $this;
    }

    /**
     * @return string
     */
    public function getBasename(): string
    {
        return $this->basename;
    }

    /**
     * @param string $basename
     *
     * @return FileDuplicate
     */
    public function setBasename(string $basename): FileDuplicate
    {
        $this->basename = $basename;

        return $this;
    }

    /**
     * @return string
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * @param string $extension
     *
     * @return FileDuplicate
     */
    public function setExtension(string $extension): FileDuplicate
    {
        $this->extension = $extension;

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
     * @return string[]
     */
    public function getNew(): array
    {
        return $this->new;
    }

    /**
     * @param string[] $new
     *
     * @return FileDuplicate
     */
    public function setNew(array $new): FileDuplicate
    {
        $this->new = $new;

        return $this;
    }

    /**
     * @param string $filename
     *
     * @return FileDuplicate
     */
    public function addNew(string $filename): FileDuplicate
    {
        $this->new[] = $filename;

        return $this;
    }
}
