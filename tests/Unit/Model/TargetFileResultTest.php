<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\TargetFileResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the TargetFileResult value object's three factory methods
 * and their corresponding getter behaviour.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TargetFileResult::class)]
final class TargetFileResultTest extends TestCase
{
    /**
     * Verifies that TargetFileResult::success() correctly stores the target file
     * and that the instance reports itself as not skipped and not an error.
     */
    #[Test]
    public function successCarriesTargetFile(): void
    {
        $file   = new SplFileInfo('/tmp/target.jpg');
        $result = TargetFileResult::success($file);

        self::assertSame($file, $result->getTargetFile());
        self::assertNull($result->getSkipReason());
        self::assertFalse($result->isSkipped());
        self::assertFalse($result->isError());
    }

    /**
     * Verifies that TargetFileResult::skipped() correctly captures the reason
     * for skipping and that the instance reports itself as skipped but not as an error.
     */
    #[Test]
    public function skippedCarriesReason(): void
    {
        $result = TargetFileResult::skipped('no capture date');

        self::assertNull($result->getTargetFile());
        self::assertSame('no capture date', $result->getSkipReason());
        self::assertTrue($result->isSkipped());
        self::assertFalse($result->isError());
    }

    /**
     * Verifies that TargetFileResult::error() correctly captures the reason
     * for skipping due to a processing error and that the instance reports
     * itself as both skipped and an error.
     */
    #[Test]
    public function errorCarriesReasonAndFlag(): void
    {
        $result = TargetFileResult::error('audio sample entry vendor must be 0');

        self::assertNull($result->getTargetFile());
        self::assertSame('audio sample entry vendor must be 0', $result->getSkipReason());
        self::assertTrue($result->isSkipped());
        self::assertTrue($result->isError());
    }
}
