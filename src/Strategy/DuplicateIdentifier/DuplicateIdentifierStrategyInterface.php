<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifier;

use SplFileInfo;

/**
 * Contract for strategies that produce a grouping key from a source/target
 * file pair. Files sharing the same identifier end up in one FileDuplicate
 * group. Different implementations enable grouping by target basename,
 * full target filename, full target pathname or content hash.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface DuplicateIdentifierStrategyInterface
{
    /**
     * Produces a grouping key for the given file pair. All files that return the
     * same string will be placed into one FileDuplicate group. Returns false when
     * the identifier cannot be computed (e.g. unreadable file for hash-based strategies).
     *
     * @param SplFileInfo $sourceFileInfo Original file on disk
     * @param SplFileInfo $targetFileInfo Computed target file with the rename strategy applied
     *
     * @return string|false Grouping key, or false on failure
     */
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false;
}
