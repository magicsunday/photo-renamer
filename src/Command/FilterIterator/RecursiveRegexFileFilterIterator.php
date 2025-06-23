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
 * A class that implements a recursive filter iterator. It recursively searches a directory tree and returns
 * only those files whose path matches a configured regular expression.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RecursiveRegexFileFilterIterator extends RecursiveFilterIterator
{
    /**
     * @var string
     */
    private readonly string $regex;

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

        // Check if the current element is a directory: always accept (so recursion works)
        if ($fileInfo->isDir()) {
            return true;
        }

        // Only files that match the regex are accepted
        return $fileInfo->isFile()
            && (preg_match($this->regex, $fileInfo->getFilename()) === 1);
    }

    /**
     * Return the inner iterator's children contained in a RecursiveFilterIterator.
     *
     * @return RecursiveRegexFileFilterIterator
     *
     * @throws RuntimeException
     */
    public function getChildren(): RecursiveRegexFileFilterIterator
    {
        if (!($this->getInnerIterator() instanceof RecursiveIterator)) {
            throw new RuntimeException('Missing "getChildren" method in inner iterator');
        }

        return new RecursiveRegexFileFilterIterator(
            $this->getInnerIterator()->getChildren(),
            $this->regex
        );
    }
}
