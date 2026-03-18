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
 * Immutable value object carrying pipeline-computed results for a single
 * rename execution. Created after the scan/group/assign phases and passed
 * alongside {@see RenameOptions} to the file system service for summary output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameResult
{
    /**
     * @param int                 $scannedFiles      Total number of files discovered during the scan phase
     * @param int                 $namingCollisions  Count of target filename collisions resolved by the safe-rename fallback
     * @param list<SkippedFile>   $skippedFiles      Files skipped because the rename strategy produced no target filename
     * @param array<string, true> $fallbackDateFiles Pathnames of files using the DateTime (0x0132) fallback
     */
    public function __construct(
        public int $scannedFiles = 0,
        public int $namingCollisions = 0,
        public array $skippedFiles = [],
        public array $fallbackDateFiles = [],
    ) {
    }
}
