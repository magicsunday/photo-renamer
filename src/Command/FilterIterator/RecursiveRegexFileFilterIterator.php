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
 * Recursive file filter that accepts directories unconditionally (to enable
 * recursion) and only accepts files whose filename matches the configured
 * PCRE regular expression. Used to restrict rename commands to specific
 * file types (e.g. image/video extensions for the EXIF command).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RecursiveRegexFileFilterIterator extends RecursiveFilterIterator
{
    /**
     * @param RecursiveIterator<string, SplFileInfo> $iterator Inner directory iterator to filter
     * @param string                                 $regex    PCRE pattern matched against filenames
     */
    public function __construct(
        RecursiveIterator $iterator,
        private readonly string $regex,
    ) {
        parent::__construct($iterator);
    }

    /**
     * Accepts all directories (enabling recursive descent) and files whose
     * filename matches the configured regex. Rejects non-file, non-directory entries.
     */
    public function accept(): bool
    {
        /** @var SplFileInfo $fileInfo */
        $fileInfo = $this->getInnerIterator()->current();

        // Check if the current element is a directory: always accept (so recursion works)
        if ($fileInfo->isDir()) {
            return true;
        }

        // Only files that match the regex are accepted
        return $fileInfo->isFile()
            && (preg_match($this->regex, $fileInfo->getFilename()) === 1);
    }

    /**
     * Creates a new filter instance wrapping the inner iterator's children,
     * propagating the same regex to the child level.
     *
     * @return self Filter for the child directory
     *
     * @throws RuntimeException When the inner iterator does not support getChildren()
     */
    public function getChildren(): self
    {
        if (!$this->getInnerIterator() instanceof RecursiveIterator) {
            throw new RuntimeException('Missing "getChildren" method in inner iterator');
        }

        return new self(
            $this->getInnerIterator()->getChildren(),
            $this->regex
        );
    }
}
