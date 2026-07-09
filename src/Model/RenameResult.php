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
 * Represents the final state and statistics of a complete renaming operation.
 *
 * This immutable value object aggregates all relevant metadata, statistics,
 * and edge-case detections from the entire renaming pipeline (scanning,
 * grouping, and classification phases). It is primarily used to provide
 * a comprehensive summary to the user at the end of an execution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameResult
{
    /**
     * @param int                         $scannedFiles               The total number of files discovered during the initial scan phase.
     * @param int                         $namingCollisions           The number of target filename collisions that had to be resolved.
     * @param list<SkippedFile>           $skippedFiles               The list of files that were skipped during the process (e.g., due to errors or missing metadata).
     * @param array<string, true>         $fallbackDateFiles          A map (pathname => true) of files where the capture date was retrieved from a fallback metadata field.
     * @param array<string, true>         $ambiguousTimezoneFiles     A map (pathname => true) of files with ambiguous timezone data (e.g., UTC vs. local time).
     * @param array<string, true>         $livePhotoConflictFiles     A map (pathname => true) of files identified as potential Live Photo pairs that have conflicting content identifiers.
     * @param list<array{string, string}> $crossDirectoryCompanions   A list of Live Photo pairs where canonical and companion are located in different directories.
     * @param list<OutputEntry>           $reviewEntries              Output-ready review findings produced from structured pipeline facts.
     * @param int                         $crossGroupVideoReviewCount Number of cross-group video review findings carried into the output boundary.
     */
    public function __construct(
        public int $scannedFiles = 0,
        public int $namingCollisions = 0,
        public array $skippedFiles = [],
        public array $fallbackDateFiles = [],
        public array $ambiguousTimezoneFiles = [],
        public array $livePhotoConflictFiles = [],
        public array $crossDirectoryCompanions = [],
        public array $reviewEntries = [],
        public int $crossGroupVideoReviewCount = 0,
    ) {
    }
}
