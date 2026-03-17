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
use MagicSunday\Renamer\Model\RenameOptions;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Contract for file system operations: directory scanning, file counting
 * and executing the actual rename/copy batch with progress and summary output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface FileSystemServiceInterface
{
    /**
     * Creates a file iterator for the given directory.
     *
     * @param string                                      $directory         The directory to iterate
     * @param RecursiveIterator<string, SplFileInfo>|null $recursiveIterator
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> The file iterator
     */
    public function createFileIterator(string $directory, ?RecursiveIterator $recursiveIterator = null): RecursiveIteratorIterator;

    /**
     * Renames all the files in the collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection of file duplicates
     * @param RenameOptions           $options                 Options controlling the rename operation
     *
     * @throws RuntimeException If a file could not be renamed
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
    ): void;
}
