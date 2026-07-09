<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

/**
 * Immutable counters returned after skipped files have been projected.
 *
 * RenameOutputRenderer appends skipped files, review entries, and cross-
 * directory companion info lines as a post-processing step. This DTO preserves
 * the resulting counters without relying on a positional `[skipped, error]`
 * tuple.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SkippedFileAppendResult
{
    /**
     * @param int $skippedCount Number of skipped (non-error) files appended to the output
     * @param int $errorCount   Number of error-tagged skipped files appended to the output
     */
    public function __construct(
        public int $skippedCount,
        public int $errorCount,
    ) {
    }
}
