<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifier;

use Override;
use SplFileInfo;

/**
 * Groups files by their full target pathname (directory + filename). Files in
 * different subdirectories with the same filename are treated as distinct groups,
 * making this strategy suitable for recursive directory renames where path
 * context must be preserved.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class TargetPathnameStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * Returns the full target pathname as the grouping key, ensuring files in
     * different directories are never grouped together.
     *
     * @param SplFileInfo $sourceFileInfo Unused by this strategy
     * @param SplFileInfo $targetFileInfo Target file whose full pathname is used as key
     *
     * @return string|false Full target pathname
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        // We want to find duplicates in the current directory,
        // so the unique identifier must also contain the path.
        return $targetFileInfo->getPathname();
    }
}
