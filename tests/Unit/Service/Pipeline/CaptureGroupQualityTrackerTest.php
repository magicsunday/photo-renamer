<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupQualityTracker;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies quality-flag propagation during capture-group building.
 *
 * The tracker must record fallback-date and ambiguous-timezone findings when a
 * metadata-aware rename strategy reports them, while leaving the context
 * untouched for non-metadata-aware strategies.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CaptureGroupQualityTracker::class)]
final class CaptureGroupQualityTrackerTest extends TestCase
{
    /**
     * Verifies that fallback and ambiguous-timezone flags are copied into the
     * pipeline context for metadata-aware strategies.
     */
    #[Test]
    public function metadataAwareStrategyFlagsAreTracked(): void
    {
        $file     = new SplFileInfo('/photos/IMG_0001.mov');
        $context  = new PipelineContext('/photos');
        $strategy = self::createMock(MetadataAwareRenameStrategyInterface::class);
        $tracker  = new CaptureGroupQualityTracker();

        $strategy->expects(self::once())
            ->method('isFallbackDateTime')
            ->with($file)
            ->willReturn(true);
        $strategy->expects(self::once())
            ->method('isAmbiguousTimezone')
            ->with($file)
            ->willReturn(true);

        $tracker->track($file, $strategy, $context);

        self::assertArrayHasKey($file->getPathname(), $context->getFallbackDateFiles());
        self::assertArrayHasKey($file->getPathname(), $context->getAmbiguousTimezoneFiles());
    }

    /**
     * Verifies that plain rename strategies do not add any quality flags because
     * they cannot supply metadata-quality semantics.
     */
    #[Test]
    public function nonMetadataAwareStrategyIsIgnored(): void
    {
        $file     = new SplFileInfo('/photos/IMG_0001.heic');
        $context  = new PipelineContext('/photos');
        $strategy = self::createStub(RenameStrategyInterface::class);
        $tracker  = new CaptureGroupQualityTracker();

        $tracker->track($file, $strategy, $context);

        self::assertSame([], $context->getFallbackDateFiles());
        self::assertSame([], $context->getAmbiguousTimezoneFiles());
    }
}
