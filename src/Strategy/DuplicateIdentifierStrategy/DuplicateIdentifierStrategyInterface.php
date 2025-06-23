<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy;

use SplFileInfo;

/**
 * Interface for duplicate identifier strategies.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface DuplicateIdentifierStrategyInterface
{
    /**
     * Generates a unique identifier for a file to detect duplicates.
     *
     * @param SplFileInfo $sourceFileInfo The source file
     * @param SplFileInfo $targetFileInfo The target file
     *
     * @return string|false A unique identifier for the file or false if the file should be skipped
     */
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false;
}
