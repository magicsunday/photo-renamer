<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Renamer\Service\ExiftoolWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Tests for the ExiftoolWriter service. Verifies argument building for
 * both image and video branches.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExiftoolWriter::class)]
final class ExiftoolWriterTest extends TestCase
{
    /**
     * Verifies that buildArguments for a still image produces DateTimeOriginal
     * and CreateDate tags.
     */
    #[Test]
    public function buildArgumentsForImageProducesCorrectTags(): void
    {
        $writer   = new ExiftoolWriter();
        $file     = new SplFileInfo('/tmp/photo.jpg');
        $dateTime = new DateTimeImmutable('2024-05-15 14:30:00');

        $args = $writer->buildArguments($file, $dateTime, false);

        self::assertContains('-overwrite_original', $args);
        self::assertContains('-DateTimeOriginal=2024:05:15 14:30:00', $args);
        self::assertContains('-CreateDate=2024:05:15 14:30:00', $args);
        self::assertContains('/tmp/photo.jpg', $args);
        self::assertNotContains('-QuickTime:CreateDate=2024:05:15 14:30:00', $args);
    }

    /**
     * Verifies that buildArguments for a video produces QuickTime:CreateDate
     * and QuickTime:ModifyDate tags.
     */
    #[Test]
    public function buildArgumentsForVideoProducesCorrectTags(): void
    {
        $writer   = new ExiftoolWriter();
        $file     = new SplFileInfo('/tmp/video.mov');
        $dateTime = new DateTimeImmutable('2024-05-15 14:30:00');

        $args = $writer->buildArguments($file, $dateTime, true);

        self::assertContains('-overwrite_original', $args);
        self::assertContains('-QuickTime:CreateDate=2024:05:15 14:30:00', $args);
        self::assertContains('-QuickTime:ModifyDate=2024:05:15 14:30:00', $args);
        self::assertContains('/tmp/video.mov', $args);
        self::assertNotContains('-DateTimeOriginal=2024:05:15 14:30:00', $args);
    }

    /**
     * Verifies that buildArguments for a video with local-as-utc scenario
     * (preserveCreateDate=false, timezone-aware DateTime) produces correct
     * UTC CreateDate and local Keys:CreationDate.
     */
    #[Test]
    public function buildArgumentsForVideoLocalAsUtcConvertsToRealUtc(): void
    {
        $writer = new ExiftoolWriter();
        $file   = new SplFileInfo('/tmp/video.mov');
        // 16:34:58 local time (Europe/Berlin, CEST = UTC+2)
        $dateTime = new DateTimeImmutable('2014-05-07 16:34:58', new DateTimeZone('Europe/Berlin'));

        // preserveCreateDate=false → writes CreateDate (UTC) + Keys:CreationDate (local)
        $args = $writer->buildArguments($file, $dateTime, true, false);

        // CreateDate must be real UTC: 16:34:58+02:00 → 14:34:58 UTC
        self::assertContains('-QuickTime:CreateDate=2014:05:07 14:34:58', $args);
        self::assertContains('-QuickTime:ModifyDate=2014:05:07 14:34:58', $args);
        // Keys:CreationDate must be local time with offset
        self::assertContains('-Keys:CreationDate=2014:05:07 16:34:58+02:00', $args);
    }
}
