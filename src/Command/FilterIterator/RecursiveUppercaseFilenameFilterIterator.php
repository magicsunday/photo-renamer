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

/**
 * A class that filters files to include only those whose filename contains at least one uppercase letter.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RecursiveUppercaseFilenameFilterIterator extends RecursiveRegexFileFilterIterator
{
    /**
     * Constructor.
     *
     * @param RecursiveIterator $iterator
     */
    public function __construct(
        RecursiveIterator $iterator,
    ) {
        // Regex searches for at least one capital letter in the file name
        parent::__construct($iterator, '/[A-Z]/');
    }
}
