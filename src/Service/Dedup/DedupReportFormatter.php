<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dedup;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Service\Output\SummaryRow;

use function sprintf;

/**
 * Formats operator-facing dedup output such as the post-scan overview, action
 * lines, and footer summary rows.
 *
 * The dedup command still owns scanning, confirmation, moving, and deleting.
 * This formatter only centralizes the human-facing wording and layout so the
 * command no longer mixes orchestration with repeated presentation details.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class DedupReportFormatter
{
    /**
     * Builds the post-scan overview shown before individual dedup actions.
     *
     * The overview either reports grouped duplicate/orphan counts and the
     * selected action, or emits the historical green no-op message when no
     * duplicate files were found in the scanned set.
     *
     * @param int  $duplicateCount  Number of duplicate files found
     * @param int  $actionableCount Number of duplicates whose original still exists
     * @param int  $orphanCount     Number of duplicate files without an actionable original
     * @param bool $delete          Whether the command will delete instead of move
     * @param bool $scannedAnyFiles Whether the scan actually saw files
     *
     * @return list<string> Console lines for the overview block
     */
    public function formatOverviewLines(
        int $duplicateCount,
        int $actionableCount,
        int $orphanCount,
        bool $delete,
        bool $scannedAnyFiles,
    ): array {
        if ($duplicateCount === 0) {
            return $scannedAnyFiles
                ? ['<fg=green>No duplicate files found — nothing to do.</>']
                : [];
        }

        $action = $delete ? 'delete' : 'move';
        $lines  = [
            sprintf(
                '<fg=cyan>Found %d duplicate file(s) (%d actionable, %d orphaned).</>',
                $duplicateCount,
                $actionableCount,
                $orphanCount,
            ),
        ];

        if ($actionableCount > 0) {
            $lines[] = sprintf('  Action: <fg=yellow>%s</> duplicates whose original still exists.', $action);
        }

        return $lines;
    }

    /**
     * Formats one duplicate action as the historical two-line block.
     *
     * Long relative paths stay on the first line, while the actual action is
     * shown on the indented second line for easier scanning.
     *
     * @param string $tagColor     Console color name used for the `[D]` tag
     * @param string $relativePath Duplicate file path relative to the source root
     * @param string $actionText   Action description shown after the arrow
     *
     * @return string Multi-line console block for one duplicate action
     */
    public function formatIndentedAction(string $tagColor, string $relativePath, string $actionText): string
    {
        return sprintf(
            '<fg=%s>[D]</> %s' . "\n" . '     <fg=cyan>→</> %s',
            $tagColor,
            $relativePath,
            $actionText,
        );
    }

    /**
     * Builds the footer summary rows for dedup execution.
     *
     * @param int $scannedFiles     Total number of scanned files
     * @param int $duplicatesFound  Number of actionable duplicates identified
     * @param int $orphanedCount    Number of orphaned duplicate files
     * @param int $spaceReclaimable Total reclaimable duplicate size in bytes
     *
     * @return list<SummaryRow> Summary rows for RenameOutputRenderer footer rendering
     */
    public function formatSummaryRows(
        int $scannedFiles,
        int $duplicatesFound,
        int $orphanedCount,
        int $spaceReclaimable,
    ): array {
        $rows = [
            new SummaryRow('Scanned files', (string) $scannedFiles),
            new SummaryRow('Duplicates found', (string) $duplicatesFound),
        ];

        if ($orphanedCount > 0) {
            $rows[] = new SummaryRow('Orphaned (skipped)', (string) $orphanedCount);
        }

        $rows[] = new SummaryRow('Space reclaimable', FileHelper::formatSize($spaceReclaimable));

        return $rows;
    }
}
