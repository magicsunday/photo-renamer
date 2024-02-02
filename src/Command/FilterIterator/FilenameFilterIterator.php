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
use RecursiveIterator;

/**
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/renamer/
 */
class FilenameFilterIterator extends RecursiveFilterIterator
{
    /**
     * @var string
     */
    protected string $regex;

    /**
     * Constructor.
     *
     * @param RecursiveIterator $iterator
     * @param string            $regex
     */
    public function __construct(
        RecursiveIterator $iterator,
        string $regex
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
        return $this->current()->isDir()
            || preg_match($this->regex, $this->current()->getFilename());
    }

    /**
     * Return the inner iterator's children contained in a RecursiveFilterIterator.
     *
     * @return null|RecursiveFilterIterator
     */
    public function getChildren(): ?RecursiveFilterIterator
    {
        return new static(
            $this->getInnerIterator()->getChildren(),
            $this->regex
        );
    }
}
