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
 * Strategy that groups duplicates by target basename.
 *
 * All files with the same EXIF date end up in one unified group.
 * Live Photo content identifiers are handled during companion detection,
 * not during grouping.
 */
class TargetBasenameStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * @param SplFileInfo $sourceFileInfo source file inspected for Live Photo metadata
     * @param SplFileInfo $targetFileInfo target file used as identifier source
     *
     * @return string|false
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        return $targetFileInfo->getBasename('.' . $targetFileInfo->getExtension());
    }
}
