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
 * Groups files by their target basename (filename without extension). All files
 * sharing the same EXIF date produce the same basename and land in one unified
 * group, regardless of file extension. Live Photo companion detection happens
 * downstream via content identifiers, not during this grouping phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
readonly class TargetBasenameStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * Extracts the target filename without its extension. For example,
     * "20230101_120000.jpg" yields "20230101_120000" as the grouping key.
     *
     * @param SplFileInfo $sourceFileInfo Unused by this strategy
     * @param SplFileInfo $targetFileInfo Target file whose basename (minus extension) is used
     *
     * @return string|false Basename without extension
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        return $targetFileInfo->getBasename('.' . $targetFileInfo->getExtension());
    }
}
