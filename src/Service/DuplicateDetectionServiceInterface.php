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
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveIterator;
use RecursiveIteratorIterator;

/**
 * Contract for the two-phase duplicate detection pipeline: grouping files by
 * a duplicate identifier, then assigning sequential filenames within each group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface DuplicateDetectionServiceInterface
{
    /**
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner>    $iterator                    iterator yielding candidate files
     * @param RenameStrategyInterface              $renameStrategy              strategy used to generate target filenames
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy strategy that identifies duplicate groups
     * @param string                               $sourceDirectory             absolute path to the source directory
     * @param string                               $targetDirectory             absolute path to the target directory
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        string $sourceDirectory,
        string $targetDirectory,
    ): FileDuplicateCollection;

    /**
     * Creates a consecutive new filename for all duplicate files.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection    collection whose entries should receive duplicate filenames
     * @param string                  $sourceDirectory            absolute path to the source directory
     * @param string                  $targetDirectory            absolute path to the target directory
     * @param bool                    $useFileExtensionFromSource when true, source extension is retained
     * @param bool                    $skipHashSubGrouping        when true, content-hash sub-grouping is skipped entirely
     */
    public function createDuplicateFilenames(
        FileDuplicateCollection $fileDuplicateCollection,
        string $sourceDirectory,
        string $targetDirectory,
        bool $useFileExtensionFromSource = false,
        bool $skipHashSubGrouping = false,
    ): FileDuplicateCollection;

    /**
     * Returns the number of groups where content-hash sub-grouping was applied.
     */
    public function getNamingCollisions(): int;

    /**
     * Returns the number of files scanned during the last call to
     * {@see groupFilesByDuplicateIdentifier()}.
     */
    public function getLastScannedFileCount(): int;

    /**
     * Returns files skipped during the last grouping pass because the rename
     * strategy could not produce a target filename.
     *
     * @return list<SkippedFile>
     */
    public function getSkippedFiles(): array;

    /**
     * Returns pathnames of files whose capture date was derived from the
     * fallback DateTime tag (0x0132) instead of DateTimeOriginal or CreateDate.
     *
     * @return array<string, true>
     */
    public function getFallbackDateFiles(): array;
}
