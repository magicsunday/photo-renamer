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
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
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
     * Collects all regular files from the given directory into a flat list.
     *
     * @param string $directory Absolute directory path to scan
     *
     * @return list<SplFileInfo> All files found in the directory tree
     */
    public function collectFiles(string $directory): array;

    /**
     * Renames all the files in the collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection of file duplicates
     * @param RenameOptions           $options                 Options controlling the rename operation
     * @param RenameResult            $result                  Pipeline-computed results (scanned files, collisions, skips)
     * @param list<string>|null       $showFilter              When set, only output entries matching these tags are shown
     *
     * @throws RuntimeException If a file could not be renamed
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
        RenameResult $result = new RenameResult(),
        ?array $showFilter = null,
    ): void;

    /**
     * Execute a runtime plan, performing the actual file rename operations.
     * Builds an occupied-path index from the plan and uses runtime collision
     * fallback as a safety layer.
     *
     * @param ExecutionPlan $plan   The runtime execution plan
     * @param bool          $dryRun When true, simulate without touching filesystem
     *
     * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int}
     */
    public function executePlan(
        ExecutionPlan $plan,
        bool $dryRun = false,
    ): array;
}
