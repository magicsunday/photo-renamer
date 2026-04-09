<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

use MagicSunday\Renamer\Service\Output\SummaryRow;

use function count;
use function in_array;
use function sort;

/**
 * Formats verify category sections and summary rows for console rendering.
 *
 * The verify command still controls scanning, filtering, and IO. This
 * formatter only centralizes how categorized findings become display sections
 * and summary rows so the command no longer mixes orchestration with report
 * assembly details.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class VerifyReportFormatter
{
    /**
     * Builds display-ready category sections from the categorized findings.
     *
     * Hidden categories are filtered out first. Remaining file lists are sorted
     * so output remains stable and idempotent across runs.
     *
     * @param array<string, list<string>> $categories Categorized verify findings
     * @param list<string>|null           $showFilter Optional category filter from `--show`
     *
     * @return list<VerifyCategorySection> Ordered display sections for command rendering.
     */
    public function formatCategorySections(array $categories, ?array $showFilter): array
    {
        $sections = [];

        foreach (VerifyCategoryCatalog::LABELS as $categoryId => $label) {
            if (($showFilter !== null) && (!in_array($categoryId, $showFilter, true))) {
                continue;
            }

            $files = $categories[$categoryId];

            if ($files === []) {
                continue;
            }

            sort($files);

            $sections[] = new VerifyCategorySection(
                $label,
                $files,
                str_contains($files[0], "\n"),
            );
        }

        return $sections;
    }

    /**
     * Builds the summary rows shown at the end of verify output.
     *
     * @param int                         $scanned    Total number of files scanned
     * @param int                         $ok         Files with no findings after all checks
     * @param array<string, list<string>> $categories Categorized verify findings
     *
     * @return list<SummaryRow> Rows for RenameOutputRenderer summary rendering
     */
    public function formatSummaryRows(int $scanned, int $ok, array $categories): array
    {
        $rows = [
            new SummaryRow('Scanned files', (string) $scanned),
            new SummaryRow('OK', (string) $ok),
        ];

        foreach (VerifyCategoryCatalog::LABELS as $categoryId => $label) {
            $count = count($categories[$categoryId]);

            if ($count > 0) {
                $rows[] = new SummaryRow($label, (string) $count);
            }
        }

        return $rows;
    }
}
