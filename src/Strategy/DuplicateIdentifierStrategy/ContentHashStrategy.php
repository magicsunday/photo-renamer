<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy;

use Override;
use SplFileInfo;

/**
 * Strategy that identifies duplicates based on file content hash.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class ContentHashStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * Generates a unique identifier for a file based on its content hash.
     *
     * @param SplFileInfo $sourceFileInfo The source file
     * @param SplFileInfo $targetFileInfo The target file
     *
     * @return string|false A unique identifier for the file or false if the file should be skipped
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        // We want to find duplicates across all directories based on a hash of the file contents.
        return hash_file('xxh128', $sourceFileInfo->getPathname());
    }
}
