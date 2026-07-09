<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\WriteDate;

/**
 * Immutable result of the write-date candidate scan.
 *
 * The command needs both the list of planned writes and the counters for skipped
 * reasons to render summaries consistent with the legacy output. This result
 * keeps that scan state explicit and avoids parallel mutable arrays in the CLI
 * layer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class WriteDateScanResult
{
    /**
     * @param int                         $scannedFiles     Total number of files scanned
     * @param int                         $alreadyCorrect   Files that needed no write after all filters and checks
     * @param int                         $noDateInName     Files skipped because no filename date could be extracted
     * @param int                         $readErrors       Files skipped because metadata could not be read
     * @param int                         $unsupportedWrite Files skipped because metadata writing is not supported safely
     * @param list<WriteDatePendingWrite> $pendingWrites    Planned metadata writes that remain after all filtering
     */
    public function __construct(
        public int $scannedFiles,
        public int $alreadyCorrect,
        public int $noDateInName,
        public int $readErrors,
        public int $unsupportedWrite,
        public array $pendingWrites,
    ) {
    }
}
