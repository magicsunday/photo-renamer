<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Interface for file system operations.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface FileSystemServiceInterface
{
    /**
     * Creates a file iterator for the given directory.
     *
     * @param string                 $directory         The directory to iterate
     * @param RecursiveIterator|null $recursiveIterator
     *
     * @return RecursiveIteratorIterator The file iterator
     */
    public function createFileIterator(string $directory, ?RecursiveIterator $recursiveIterator = null): RecursiveIteratorIterator;

    /**
     * Counts the number of files in the given iterator.
     *
     * @param RecursiveIteratorIterator $iterator The file iterator
     *
     * @return int The number of files
     */
    public function countFiles(RecursiveIteratorIterator $iterator): int;

    /**
     * Renames all the files in the collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection of file duplicates
     * @param bool                    $dryRun                  Whether to perform a dry run (no actual renaming)
     * @param bool                    $skipDuplicates          Whether to skip duplicate files
     * @param bool                    $copyFiles               Whether to copy files instead of renaming them
     *
     * @return void
     *
     * @throws RuntimeException If a file could not be renamed
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $dryRun = false,
        bool $skipDuplicates = false,
        bool $copyFiles = false,
    ): void;
}
