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
     * Creates a collection of duplicates.
     *
     * Files with the same unique identifier are grouped together into the same
     * {@see FileDuplicate} instance.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner>    $iterator                    The iterator yielding candidate files.
     * @param RenameStrategyInterface              $renameStrategy              The strategy used to generate target filenames.
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy The strategy that identifies duplicate groups.
     * @param string                               $sourceDirectory             The absolute path to the source directory.
     *
     * @return FileDuplicateCollection The collection of grouped duplicates.
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        string $sourceDirectory,
    ): FileDuplicateCollection;

    /**
     * Creates consecutive new filenames for all duplicate files.
     *
     * Assigns a sequential duplicate suffix (e.g. "-duplicate-1") to all files
     * in each group except for the canonical representative.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection    The collection whose entries should receive duplicate filenames.
     * @param string                  $sourceDirectory            The absolute path to the source directory.
     * @param bool                    $useFileExtensionFromSource When true, the source extension is retained.
     * @param bool                    $skipHashSubGrouping        When true, content-hash sub-grouping is skipped entirely.
     *
     * @return FileDuplicateCollection The updated collection with generated filenames.
     */
    public function createDuplicateFilenames(
        FileDuplicateCollection $fileDuplicateCollection,
        string $sourceDirectory,
        bool $useFileExtensionFromSource = false,
        bool $skipHashSubGrouping = false,
    ): FileDuplicateCollection;

    /**
     * Returns the number of groups where content-hash sub-grouping was applied.
     *
     * @return int Total count of naming collisions.
     */
    public function getNamingCollisions(): int;

    /**
     * Returns the number of files scanned during the last grouping pass.
     *
     * @return int Total scanned file count.
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

    /**
     * Returns pathnames of files with an ambiguous timezone.
     *
     * A timezone is ambiguous when the QuickTime timestamp could be UTC or
     * local time but we cannot determine which.
     *
     * @return array<string, true> Map of pathnames with ambiguous timezone.
     */
    public function getAmbiguousTimezoneFiles(): array;

    /**
     * Returns pathnames of files that look like a Live Photo pair by fallback
     * heuristics but expose conflicting non-null content identifiers.
     *
     * These files are surfaced as review candidates and skipped from rename.
     *
     * @return array<string, true>
     */
    public function getLivePhotoConflictFiles(): array;

    /**
     * Releases all cached hash results to free memory after the pipeline completes.
     */
    public function clearHashCache(): void;
}
