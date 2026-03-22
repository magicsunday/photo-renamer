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
 * Groups files by their target basename (filename without extension), regardless
 * of directory. Files across the entire directory tree sharing the same EXIF date
 * produce the same identifier and land in one unified group. This enables
 * cross-directory duplicate detection in large photo collections.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TargetBasenameStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * Returns the target basename (without extension) as the grouping key.
     * For example, "/photos/2025/2025-01-01_12-00-00-000.jpg" yields
     * "2025-01-01_12-00-00-000".
     *
     * @param SplFileInfo $sourceFileInfo Unused by this strategy
     * @param SplFileInfo $targetFileInfo Target file whose basename is used
     *
     * @return string Cross-directory identifier
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string
    {
        return FileHelper::basenameWithoutExtension($targetFileInfo);
    }
}
