<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command\FilterIterator;

use RecursiveFilterIterator;
use SplFileInfo;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class FileFilterIterator extends RecursiveFilterIterator
{
    /**
     * Check whether the current element of the iterator is acceptable.
     *
     * @return bool
     */
    public function accept(): bool
    {
        /** @var SplFileInfo $fileInfo */
        $fileInfo = $this->current();

        if ($fileInfo->isDir()) {
            return true;
        }

        return $fileInfo->isFile();
    }
}
