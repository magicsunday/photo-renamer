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
 * Groups files by their full target filename including extension. Unlike
 * TargetBasenameStrategy, files with different extensions but the same stem
 * end up in separate groups. Used by rename commands that do not need
 * cross-extension grouping (e.g. pattern-based or lowercase renames).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class TargetFilenameStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * Returns the full target filename (including extension) as the grouping key.
     *
     * @param SplFileInfo $sourceFileInfo Unused by this strategy
     * @param SplFileInfo $targetFileInfo Target file whose filename is used as key
     *
     * @return string|false Full target filename
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        return $targetFileInfo->getFilename();
    }
}
