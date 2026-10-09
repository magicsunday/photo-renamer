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
use MagicSunday\Renamer\Exception\ExiftoolWriteException;
use MagicSunday\Renamer\Service\ExiftoolWriter;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function chmod;
use function file_put_contents;
use function str_repeat;
use function strlen;

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
    use WorkspaceTrait;

    /**
     * Exercises real subprocesses so exit status, stderr bounds and timeout
     * conversion cannot be accidentally replaced with a boolean success flag.
     *
     * @param string $script     Body of a deterministic executable fixture
     * @param float  $timeout    Maximum runtime in seconds
     * @param string $diagnostic Expected failure description
     */
    #[Test]
    #[DataProvider('failedProcessCases')]
    public function failedProcessesPreserveBoundedDiagnostics(string $script, float $timeout, string $diagnostic): void
    {
        $workspace = $this->createTempWorkspace();
        $binary    = $workspace . '/exiftool-fixture';
        file_put_contents($binary, "#!/bin/sh\n" . $script);
        chmod($binary, 0700);

        try {
            try {
                new ExiftoolWriter($binary, $timeout)->writeDateTime(new SplFileInfo($workspace . '/photo.jpg'), new DateTimeImmutable('2024-01-01'), false);
                self::fail('A failed subprocess must not report success.');
            } catch (ExiftoolWriteException $exception) {
                self::assertStringContainsString($diagnostic, $exception->getMessage());
                self::assertLessThan(2200, strlen($exception->getMessage()));
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Includes silent failure and excessive stderr as well as ordinary failure
     * and a timeout; stdout is irrelevant to the diagnostic contract.
     *
     * @return iterable<string, array{string, float, string}> Process failure cases
     */
    public static function failedProcessCases(): iterable
    {
        yield 'nonzero with stderr' => ["echo 'synthetic write failure' >&2\nexit 9\n", 5.0, 'Exiftool exited with code 9: synthetic write failure'];
        yield 'silent nonzero' => ["exit 8\n", 5.0, 'Exiftool exited with code 8: No error output.'];
        yield 'large stderr' => ["echo '" . str_repeat('x', 10000) . "' >&2\nexit 7\n", 5.0, 'Exiftool exited with code 7: xxx'];
        yield 'timeout' => ["exec sleep 5\n", 0.05, 'Exiftool timed out after 0.05 seconds.'];
    }

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
        // Track/Media dates must NOT be written (may not exist in all files)
        self::assertNotContains('-TrackCreateDate=2024:05:15 14:30:00', $args);
        self::assertNotContains('-MediaCreateDate=2024:05:15 14:30:00', $args);
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

        // Movie-header dates must be real UTC: 16:34:58+02:00 → 14:34:58 UTC
        self::assertContains('-QuickTime:CreateDate=2014:05:07 14:34:58', $args);
        self::assertContains('-QuickTime:ModifyDate=2014:05:07 14:34:58', $args);
        // Keys:CreationDate must be local time with offset
        self::assertContains('-Keys:CreationDate=2014:05:07 16:34:58+02:00', $args);
    }
}
