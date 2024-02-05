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
class FileInfo
{
    /**
     * @var string
     */
    protected string $path = '';

    /**
     * @var SplFileInfo[]
     */
    protected array $files = [];

    /**
     * @var string
     */
    protected string $exifDateTimeOriginal = '';

    /**
     * @var string
     */
    protected string $exifSubSecTimeOriginal = '';

    /**
     * @var int
     */
    protected int $filesize = 0;

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
     * @param SplFileInfo $fileInfo
     *
     * @return FileInfo
     */
    public function addFile(SplFileInfo $fileInfo): FileInfo
    {
        $this->files[] = $fileInfo;

        return $this;
    }

    /**
     * @return string
     */
    public function getExifDateTimeOriginal(): string
    {
        return $this->exifDateTimeOriginal;
    }

    /**
     * @param string $exifDateTimeOriginal
     *
     * @return FileInfo
     */
    public function setExifDateTimeOriginal(string $exifDateTimeOriginal): FileInfo
    {
        $this->exifDateTimeOriginal = $exifDateTimeOriginal;

        return $this;
    }

    /**
     * @return string
     */
    public function getExifSubSecTimeOriginal(): string
    {
        return $this->exifSubSecTimeOriginal;
    }

    /**
     * @param string $exifSubSecTimeOriginal
     *
     * @return FileInfo
     */
    public function setExifSubSecTimeOriginal(string $exifSubSecTimeOriginal): FileInfo
    {
        $this->exifSubSecTimeOriginal = $exifSubSecTimeOriginal;

        return $this;
    }

    /**
     * @return int
     */
    public function getFilesize(): int
    {
        return $this->filesize;
    }

    /**
     * @param int $filesize
     *
     * @return FileInfo
     */
    public function setFilesize(int $filesize): FileInfo
    {
        $this->filesize = $filesize;

        return $this;
    }
}
