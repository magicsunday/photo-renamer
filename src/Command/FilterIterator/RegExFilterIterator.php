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
use RecursiveIterator;
use RuntimeException;
use SplFileInfo;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RegExFilterIterator extends RecursiveFilterIterator
{
    /**
     * @var string
     */
    private string $regex;

    /**
     * Constructor.
     *
     * @param RecursiveIterator $iterator
     * @param string            $regex
     */
    public function __construct(
        RecursiveIterator $iterator,
        string $regex,
    ) {
        parent::__construct($iterator);

        $this->regex = $regex;
    }

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

        return $fileInfo->isFile()
            && (preg_match($this->regex, $fileInfo->getFilename()) === 1);
    }

    /**
     * Return the inner iterator's children contained in a RecursiveFilterIterator.
     *
     * @return RegExFilterIterator
     *
     * @throws RuntimeException
     */
    public function getChildren(): RegExFilterIterator
    {
        if (!($this->getInnerIterator() instanceof RecursiveIterator)) {
            throw new RuntimeException('Missing "getChildren" method in inner iterator');
        }

        return new RegExFilterIterator(
            $this->getInnerIterator()->getChildren(),
            $this->regex
        );
    }
}
