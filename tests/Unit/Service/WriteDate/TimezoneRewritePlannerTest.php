<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\WriteDate;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\WriteDate\TimezoneRewritePlan;
use MagicSunday\Renamer\Service\WriteDate\TimezoneRewritePlanner;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the timezone-specific planning logic used by write-date before metadata
 * writes are scheduled.
 *
 * The planner must preserve the existing local clock time for `--local-as-utc`,
 * convert real UTC QuickTime timestamps into local time in the default case, and
 * signal when the original QuickTime CreateDate needs to stay untouched while only
 * Keys:CreationDate receives the local offset.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TimezoneRewritePlanner::class)]
#[UsesClass(TimezoneRewritePlan::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
final class TimezoneRewritePlannerTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that `--local-as-utc` keeps the observed clock time and merely
     * attaches the configured timezone offset.
     */
    #[Test]
    public function localAsUtcKeepsObservedClockTime(): void
    {
        $workspace = $this->createTempWorkspace('tzplanner_');
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2014-04-26.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2014-04-26T15:43:33+00:00'),
                    null,
                    false,
                    true,
                ),
            );

            $planner = new TimezoneRewritePlanner(new ExifMetadataProvider($metadataExtractor));

            $plan = $planner->plan(
                new SplFileInfo($movPath),
                'timezone',
                new DateTimeImmutable('2014-04-26 00:00:00'),
                false,
                true,
                new DateTimeZone('Europe/Berlin'),
            );

            self::assertSame('2014-04-26 15:43:33 Europe/Berlin', $plan->writeDateTime->format('Y-m-d H:i:s e'));
            self::assertFalse($plan->preserveCreateDate);
        } finally {
            @unlink($movPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that the default timezone-repair mode converts a real UTC QuickTime
     * timestamp into local time and preserves the original CreateDate atom.
     */
    #[Test]
    public function defaultTimezoneRepairConvertsUtcAndPreservesCreateDate(): void
    {
        $workspace = $this->createTempWorkspace('tzplanner_');
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2014-05-07.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2014-05-07T14:34:58+00:00'),
                    null,
                    false,
                    true,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    false,
                    new DateTimeImmutable('2014-05-07T14:34:58+00:00'),
                ),
            );

            $planner = new TimezoneRewritePlanner(new ExifMetadataProvider($metadataExtractor));

            $plan = $planner->plan(
                new SplFileInfo($movPath),
                'timezone',
                new DateTimeImmutable('2014-05-07 00:00:00'),
                false,
                false,
                new DateTimeZone('Europe/Berlin'),
            );

            self::assertSame('2014-05-07 16:34:58 Europe/Berlin', $plan->writeDateTime->format('Y-m-d H:i:s e'));
            self::assertTrue($plan->preserveCreateDate);
        } finally {
            @unlink($movPath);
            $this->removeWorkspace($workspace);
        }
    }
}
