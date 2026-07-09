<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

/**
 * Immutable result object for the non-Live-Photo verification scan.
 *
 * The verify command performs a metadata-oriented first pass that classifies
 * individual files and collects Live Photo content identifiers for the later
 * completeness analysis. This result keeps those two outputs explicit while
 * also carrying the scanned/ok counters needed by the summary rendering.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class VerifyScanResult
{
    /**
     * @param int                         $scannedFiles          Total number of files seen by the scanner
     * @param int                         $okCount               Number of files without immediate metadata issues
     * @param array<string, list<string>> $categories            Categorized findings excluding the later Live Photo pass
     * @param LivePhotoContentIdMap       $livePhotoContentIdMap Per-directory content-ID map for the completeness analyzer
     */
    public function __construct(
        public int $scannedFiles,
        public int $okCount,
        public array $categories,
        public LivePhotoContentIdMap $livePhotoContentIdMap,
    ) {
    }
}
