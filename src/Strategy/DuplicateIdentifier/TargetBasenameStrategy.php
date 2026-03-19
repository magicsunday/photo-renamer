<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifier;

use MagicSunday\Renamer\Helper\FileHelper;
use Override;
use SplFileInfo;

/**
 * Groups files by their target directory and basename (filename without extension).
 * Files in the same directory sharing the same EXIF date produce the same identifier
 * and land in one unified group, regardless of file extension. Files in different
 * directories are always independent, even with identical timestamps.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
readonly class TargetBasenameStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * Combines the target directory and basename (without extension) into a single
     * grouping key. For example, "/photos/2025/2025-01-01_12-00-00-000.jpg" yields
     * "/photos/2025/2025-01-01_12-00-00-000".
     *
     * @param SplFileInfo $sourceFileInfo Unused by this strategy
     * @param SplFileInfo $targetFileInfo Target file whose directory + basename is used
     *
     * @return string|false Directory-scoped identifier
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        return $targetFileInfo->getPath()
            . DIRECTORY_SEPARATOR
            . FileHelper::basenameWithoutExtension($targetFileInfo);
    }
}
