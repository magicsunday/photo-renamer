<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use SplFileInfo;

/**
 * Index of pathnames that already belong to a Live Photo group.
 */
final class LivePhotoExistingFilePathnameIndex
{
    /** @var array<string, true> */
    private array $pathnames = [];

    /**
     * Records a file pathname as belonging to a Live Photo group.
     *
     * @param SplFileInfo $file file whose pathname should be tracked
     *
     * @return void
     */
    public function remember(SplFileInfo $file): void
    {
        $this->pathnames[$file->getPathname()] = true;
    }

    /**
     * Checks whether the provided file has already been recorded.
     *
     * @param SplFileInfo $file file to look up in the index
     *
     * @return bool true when the pathname is already tracked
     */
    public function contains(SplFileInfo $file): bool
    {
        return isset($this->pathnames[$file->getPathname()]);
    }
}
