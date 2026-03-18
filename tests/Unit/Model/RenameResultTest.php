<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Model\SkippedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the value-object contract of RenameResult, the immutable carrier for
 * pipeline-computed metrics passed alongside RenameOptions to
 * FileSystemService::renameFiles().
 *
 * RenameResult captures scanned-file counts, naming collision metrics, and skipped
 * files. Correct defaults and explicit overrides are critical because the
 * FileSystemService summary output depends on every one of these values.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameResult::class)]
final class RenameResultTest extends TestCase
{
    /**
     * Verifies that a default-constructed RenameResult has all numeric counters
     * at zero and the skipped/fallback files lists empty.
     *
     * This ensures that omitting results does not accidentally inject non-zero
     * counts into the summary output.
     */
    #[Test]
    public function itUsesDefaultValues(): void
    {
        $result = new RenameResult();

        self::assertSame(0, $result->scannedFiles);
        self::assertSame(0, $result->namingCollisions);
        self::assertSame([], $result->skippedFiles);
        self::assertSame([], $result->fallbackDateFiles);
    }

    /**
     * Verifies that every constructor parameter is stored and readable from the
     * corresponding public property when non-default values are provided.
     *
     * This ensures that the command pipeline can pass arbitrary scan metrics
     * through to the file system service without any value being silently
     * dropped or overridden.
     */
    #[Test]
    public function itAcceptsCustomValues(): void
    {
        $skippedFiles = [
            new SkippedFile(new SplFileInfo('/tmp/video.mov'), 'no capture date'),
        ];

        $fallbackDateFiles = ['/tmp/scan.jpg' => true];

        $result = new RenameResult(
            scannedFiles: 42,
            namingCollisions: 3,
            skippedFiles: $skippedFiles,
            fallbackDateFiles: $fallbackDateFiles,
        );

        self::assertSame(42, $result->scannedFiles);
        self::assertSame(3, $result->namingCollisions);
        self::assertSame($skippedFiles, $result->skippedFiles);
        self::assertSame($fallbackDateFiles, $result->fallbackDateFiles);
    }
}
