<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use SplFileInfo;

/**
 * Checks whether a legacy duplicate target is occupied by another file.
 *
 * The legacy duplicate pipeline keeps an in-memory disk index of discovered
 * source files so most occupancy checks avoid filesystem `stat()` calls. This
 * worker owns the subtle rule set around "same file", "same group", and
 * "external file" so suffix assignment can delegate to a small, well-named
 * collaborator instead of carrying the disk-index details inline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class LegacyTargetOccupancyChecker
{
    /**
     * Checks whether the target path is already occupied by a file that is not
     * the source itself and not another member of the same duplicate group.
     *
     * Uses the in-memory disk index for fast lookups and falls back to the
     * filesystem only for paths outside the scanned directories.
     *
     * @param SplFileInfo         $target           Target path to check.
     * @param SplFileInfo         $source           Source file being processed and never treated as occupied.
     * @param array<string, true> $groupSourcePaths Source paths of all files in the current group.
     * @param array<string, true> $diskIndex        In-memory index of all paths discovered during scanning.
     */
    public function isOccupied(
        SplFileInfo $target,
        SplFileInfo $source,
        array $groupSourcePaths,
        array $diskIndex,
    ): bool {
        $targetPath = $target->getPathname();

        // Target is the source itself — not occupied.
        if ($targetPath === $source->getPathname()) {
            return false;
        }

        // Fast path: target is known from the scan index → exists.
        // Fallback: stat() for paths outside the scanned directories.
        if (!isset($diskIndex[$targetPath]) && (!$target->isFile())) {
            return false;
        }

        // Target exists but belongs to another file in the same group — will be freed.
        // Target exists and belongs to an external file — occupied.
        return !isset($groupSourcePaths[$targetPath]);
    }
}
