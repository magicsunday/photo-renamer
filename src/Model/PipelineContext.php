<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

/**
 * Mutable state bag passed between pipeline phases.
 * Fields are logically grouped by concern: filesystem state and analysis quality.
 * toRenameResult() converts accumulated state into an immutable RenameResult for the execution phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class PipelineContext
{
    // -------------------------------------------------------------------------
    // Filesystem state
    // -------------------------------------------------------------------------

    /**
     * Pathnames of target files already committed to disk or reserved by the
     * assign phase. Used to detect and resolve naming collisions.
     *
     * @var array<string, true>
     */
    private array $diskIndex = [];

    // -------------------------------------------------------------------------
    // Analysis quality
    // -------------------------------------------------------------------------

    /**
     * Pathnames of files whose date was sourced from the DateTime (0x0132) tag
     * rather than DateTimeOriginal because the latter was absent.
     *
     * @var array<string, true>
     */
    private array $fallbackDateFiles = [];

    /**
     * Pathnames of files whose timezone could not be determined unambiguously
     * (UTC recorded by a non-Apple camera that stores local time as UTC).
     *
     * @var array<string, true>
     */
    private array $ambiguousTimezoneFiles = [];

    /**
     * Pathnames of files that appear to be Live Photo pairs by heuristic but
     * carry conflicting non-null content identifiers.
     *
     * @var array<string, true>
     */
    private array $livePhotoConflictFiles = [];

    /**
     * Live Photo pairs where canonical and companion are in different directories.
     * Each entry is [canonicalPath, companionPath].
     *
     * @var list<array{string, string}>
     */
    private array $crossDirectoryCompanions = [];

    /**
     * Files skipped because the rename strategy could not produce a target filename.
     *
     * @var list<SkippedFile>
     */
    private array $skippedFiles = [];

    /**
     * Total number of files discovered during the scan phase.
     */
    private int $scannedFileCount = 0;

    /**
     * Count of target filename collisions resolved by the safe-rename fallback.
     */
    private int $namingCollisions = 0;

    /**
     * @param string $sourceDirectory Canonical absolute path to the directory being processed.
     */
    public function __construct(
        public readonly string $sourceDirectory,
    ) {
    }

    // -------------------------------------------------------------------------
    // Disk index
    // -------------------------------------------------------------------------

    /**
     * Marks a target pathname as occupied so the assign phase can avoid it
     * or generate a suffix if needed to prevent naming collisions.
     *
     * @param string $pathname Absolute target path to reserve.
     */
    public function markOccupied(string $pathname): void
    {
        $this->diskIndex[$pathname] = true;
    }

    /**
     * Returns true when the pathname has been marked occupied.
     *
     * @param string $pathname Absolute target path to check.
     *
     * @return bool True if the path is already reserved.
     */
    public function isOccupied(string $pathname): bool
    {
        return isset($this->diskIndex[$pathname]);
    }

    // -------------------------------------------------------------------------
    // Quality flags
    // -------------------------------------------------------------------------

    /**
     * Records a pathname as having used the DateTime (0x0132) fallback date
     * because the primary DateTimeOriginal tag was absent.
     *
     * @param string $pathname Absolute path of the affected file.
     */
    public function addFallbackDateFile(string $pathname): void
    {
        $this->fallbackDateFiles[$pathname] = true;
    }

    /**
     * Records a pathname as having an ambiguous timezone (e.g. UTC recorded
     * by a camera that stores local time as UTC without offset information).
     *
     * @param string $pathname Absolute path of the affected file.
     */
    public function addAmbiguousTimezoneFile(string $pathname): void
    {
        $this->ambiguousTimezoneFiles[$pathname] = true;
    }

    /**
     * Records a pathname as having a Live Photo content-identifier conflict
     * during the pairing phase.
     *
     * @param string $pathname Absolute path of the affected file.
     */
    public function addLivePhotoConflictFile(string $pathname): void
    {
        $this->livePhotoConflictFiles[$pathname] = true;
    }

    /**
     * Records a Live Photo pair where canonical and companion are in different directories.
     *
     * @param string $canonicalPath Absolute path of the canonical item.
     * @param string $companionPath Absolute path of the companion item.
     */
    public function addCrossDirectoryCompanion(string $canonicalPath, string $companionPath): void
    {
        $this->crossDirectoryCompanions[] = [$canonicalPath, $companionPath];
    }

    /**
     * Records a file that was skipped during the grouping phase.
     *
     * @param SkippedFile $skippedFile Skipped file entry including the reason.
     */
    public function addSkippedFile(SkippedFile $skippedFile): void
    {
        $this->skippedFiles[] = $skippedFile;
    }

    /**
     * Returns pathnames recorded as having capture date sourced from a fallback.
     *
     * @return array<string, true> Map of pathnames (path as key for O(1) access).
     */
    public function getFallbackDateFiles(): array
    {
        return $this->fallbackDateFiles;
    }

    /**
     * Returns pathnames recorded as having an ambiguous timezone.
     *
     * @return array<string, true> Map of pathnames with ambiguous timezone.
     */
    public function getAmbiguousTimezoneFiles(): array
    {
        return $this->ambiguousTimezoneFiles;
    }

    /**
     * Returns pathnames recorded as having Live Photo content-identifier conflicts.
     *
     * @return array<string, true> Map of pathnames with ID conflicts.
     */
    public function getLivePhotoConflictFiles(): array
    {
        return $this->livePhotoConflictFiles;
    }

    /**
     * Returns Live Photo pairs where files are located in different directories.
     *
     * @return list<array{string, string}> List of pairs [canonical path, companion path].
     */
    public function getCrossDirectoryCompanions(): array
    {
        return $this->crossDirectoryCompanions;
    }

    /**
     * Returns the list of files skipped during the grouping phase.
     *
     * @return list<SkippedFile> List of skipped files.
     */
    public function getSkippedFiles(): array
    {
        return $this->skippedFiles;
    }

    // -------------------------------------------------------------------------
    // Counters
    // -------------------------------------------------------------------------

    /**
     * Sets the total number of files discovered during the scan phase.
     *
     * @param int $count Total file count.
     */
    public function setScannedFileCount(int $count): void
    {
        $this->scannedFileCount = $count;
    }

    /**
     * Returns the total number of files discovered during the scan phase.
     *
     * @return int Total scanned file count.
     */
    public function getScannedFileCount(): int
    {
        return $this->scannedFileCount;
    }

    /**
     * Increments the count of resolved naming collisions.
     */
    public function incrementNamingCollisions(): void
    {
        ++$this->namingCollisions;
    }

    /**
     * Returns the count of naming collisions resolved so far.
     *
     * @return int Total number of collisions.
     */
    public function getNamingCollisions(): int
    {
        return $this->namingCollisions;
    }

    // -------------------------------------------------------------------------
    // Conversion
    // -------------------------------------------------------------------------

    /**
     * Converts the accumulated mutable state into an immutable RenameResult
     * for the final execution phase.
     *
     * @return RenameResult The analysis result.
     */
    public function toRenameResult(): RenameResult
    {
        return new RenameResult(
            scannedFiles: $this->scannedFileCount,
            namingCollisions: $this->namingCollisions,
            skippedFiles: $this->skippedFiles,
            fallbackDateFiles: $this->fallbackDateFiles,
            ambiguousTimezoneFiles: $this->ambiguousTimezoneFiles,
            livePhotoConflictFiles: $this->livePhotoConflictFiles,
            crossDirectoryCompanions: $this->crossDirectoryCompanions,
        );
    }
}
