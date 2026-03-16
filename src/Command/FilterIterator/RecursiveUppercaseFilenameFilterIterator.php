<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command\FilterIterator;

use RecursiveIterator;
use SplFileInfo;

/**
 * Pre-configured regex filter that only accepts files containing at least one
 * uppercase ASCII letter in their filename. Used by the lowercase rename command
 * to skip files that are already fully lowercased.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RecursiveUppercaseFilenameFilterIterator extends RecursiveRegexFileFilterIterator
{
    /**
     * @param RecursiveIterator<string, SplFileInfo> $iterator Inner directory iterator to filter
     */
    public function __construct(
        RecursiveIterator $iterator,
    ) {
        // Regex searches for at least one capital letter in the file name
        parent::__construct($iterator, '/[A-Z]/');
    }
}
