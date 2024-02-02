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
 * Filter iterator matching files with at least one uppercase character.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class UppercaseFilterIterator extends RegExFilterIterator
{
    /**
     * Constructor.
     *
     * @param RecursiveIterator $iterator
     */
    public function __construct(
        RecursiveIterator $iterator,
    ) {
        parent::__construct($iterator, '/[A-Z]/');
    }
}
