<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\WriteDate;

use function count;
use function sprintf;

/**
 * Formats write-date scan summaries and per-file write entries.
 *
 * The write-date command still owns scanning, confirmation, and execution. This
 * formatter only preserves the existing console wording and line layout so the
 * command can focus on control flow instead of assembling presentation strings.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class WriteDateReportFormatter
{
    /**
     * Formats the post-scan overview for pending metadata repairs.
     *
     * The command historically prints either a grouped summary before the
     * individual entries or a green no-op message when everything is already
     * correct. Keeping that branching here lets the command stay focused on
     * orchestration and execution.
     *
     * @param int                         $scannedFiles  Total number of scanned files
     * @param list<WriteDatePendingWrite> $pendingWrites Planned writes found during the scan
     *
     * @return list<string> Console lines for the post-scan overview block
     */
    public function formatOverviewLines(int $scannedFiles, array $pendingWrites): array
    {
        if ($pendingWrites === []) {
            return ($scannedFiles > 0)
                ? ['<fg=green>All files have correct metadata — nothing to do.</>']
                : [];
        }

        /** @var array<string, int> $reasonCounts */
        $reasonCounts = [];

        foreach ($pendingWrites as $entry) {
            $reasonKey                = $entry->reasonKey;
            $reasonCounts[$reasonKey] = ($reasonCounts[$reasonKey] ?? 0) + 1;
        }

        $lines = [
            sprintf(
                '<fg=cyan>Found %d file(s) needing metadata repair:</>',
                count($pendingWrites),
            ),
        ];

        foreach ($reasonCounts as $reason => $count) {
            $label   = WriteDateReasonCatalog::formatLabel($reason);
            $lines[] = sprintf('  %d %s <fg=gray>(%s)</>', $count, $count === 1 ? 'file' : 'files', $label);
        }

        return $lines;
    }

    /**
     * Formats one write-date entry with optional reason details.
     *
     * The formatter preserves the aligned two-line output: first the tag/path
     * and write target, then an optional gray reason line when the entry came
     * from a classified metadata issue.
     *
     * @param string      $tag         Colored entry tag (`[W]`, `[E]`, ...)
     * @param string      $linkedPath  Already linkified relative path for console output
     * @param string      $padding     Padding needed to align the arrow column
     * @param string      $detail      Target field or failure message rendered after the arrow
     * @param string|null $reasonKey   Stable reason key, or null when no second line should be shown
     * @param string|null $reasonLabel Human-readable reason label shown next to the key
     *
     * @return list<string> One or two console lines for the entry
     */
    public function formatEntry(
        string $tag,
        string $linkedPath,
        string $padding,
        string $detail,
        ?string $reasonKey = null,
        ?string $reasonLabel = null,
    ): array {
        $lines = [
            sprintf(' %s %s%s <fg=cyan>→</> %s', $tag, $linkedPath, $padding, $detail),
        ];

        if ($reasonKey !== null) {
            $lines[] = sprintf('      <fg=gray>[%s] %s</>', $reasonKey, $reasonLabel ?? '');
        }

        return $lines;
    }
}
