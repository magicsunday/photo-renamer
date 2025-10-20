<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use SplFileInfo;

/**
 * Index of pathnames that already belong to a Live Photo group.
 */
final class LivePhotoExistingFilePathnameIndex
{
    /** @var array<string, true> */
    private array $pathnames = [];

    public function remember(SplFileInfo $file): void
    {
        $this->pathnames[$file->getPathname()] = true;
    }

    public function contains(SplFileInfo $file): bool
    {
        return isset($this->pathnames[$file->getPathname()]);
    }
}
