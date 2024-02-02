<?php

/**
 * This file is part of the package magicsunday/renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command\FilterIterator;

use RecursiveFilterIterator;

/**
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/renamer/
 */
class FileFilterIterator extends RecursiveFilterIterator
{
    /**
     * Check whether the current element of the iterator is acceptable
     *
     * @return bool
     */
    public function accept(): bool
    {
        return $this->current()->isDir()
            || $this->current()->isFile();
    }
}
