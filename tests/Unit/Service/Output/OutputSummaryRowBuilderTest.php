<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Output;

use MagicSunday\Renamer\Service\Output\OutputSummaryRowBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the builder that projects runtime counters into summary rows before
 * they are rendered by the console boundary.
 *
 * The tests focus on the policy of which counters become visible rows and how
 * dry-run mode changes the trailing file-count label.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(OutputSummaryRowBuilder::class)]
final class OutputSummaryRowBuilderTest extends TestCase
{
    /**
     * Verifies that the builder keeps only mandatory rows and non-zero optional
     * rows so the summary stays compact.
     */
    #[Test]
    public function buildIncludesOnlyMandatoryAndNonZeroOptionalRows(): void
    {
        $builder = new OutputSummaryRowBuilder();

        self::assertSame(
            [
                ['Scanned files', '10'],
                ['Skipped (no metadata)', '2'],
                ['Planned moves', '7'],
                ['Duplicates found', '6'],
                ['Files processed', '5'],
            ],
            $builder->build([
                'scannedFiles'     => 10,
                'skippedCount'     => 2,
                'errorCount'       => 0,
                'livePhotoGroups'  => 0,
                'namingCollisions' => 0,
                'fileCount'        => 5,
                'duplicateCount'   => 6,
                'plannedMoves'     => 7,
                'plannedSkips'     => 0,
            ], false),
        );
    }

    /**
     * Verifies that cross-group video review appears only when the counter is
     * present and non-zero, and that dry-run mode switches the final label.
     */
    #[Test]
    public function buildIncludesCrossGroupReviewAndDryRunLabelWhenApplicable(): void
    {
        $builder = new OutputSummaryRowBuilder();

        self::assertSame(
            [
                ['Scanned files', '12'],
                ['Cross-group video review', '3'],
                ['Files to process', '4'],
            ],
            $builder->build([
                'scannedFiles'               => 12,
                'skippedCount'               => 0,
                'errorCount'                 => 0,
                'livePhotoGroups'            => 0,
                'namingCollisions'           => 0,
                'fileCount'                  => 4,
                'duplicateCount'             => 0,
                'plannedMoves'               => 0,
                'plannedSkips'               => 0,
                'crossGroupVideoReviewCount' => 3,
            ], true),
        );
    }
}
