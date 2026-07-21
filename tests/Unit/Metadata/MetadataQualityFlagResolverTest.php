<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Metadata;

use MagicSunday\Renamer\Metadata\MetadataQualityFlagResolver;
use MagicSunday\Renamer\Metadata\MetadataQualityFlags;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the small resolver that centralizes actionable fallback/timezone
 * flags after the primary reliability decision has already been made.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(MetadataQualityFlagResolver::class)]
#[UsesClass(MetadataQualityFlags::class)]
final class MetadataQualityFlagResolverTest extends TestCase
{
    /**
     * Verifies that reliable files suppress both secondary quality flags,
     * keeping the caller aligned with `hasReliableDateTime()` as the authority.
     */
    #[Test]
    public function resolveReturnsNoFlagsForReliableFile(): void
    {
        $file     = new SplFileInfo('/tmp/2024-01-15.jpg');
        $strategy = self::createStub(MetadataAwareRenameStrategyInterface::class);

        $strategy
            ->method('hasReliableDateTime')
            ->willReturnStrictMap([[$file, true]]);

        $flags = MetadataQualityFlagResolver::resolve($file, $strategy);

        self::assertFalse($flags->hasFallbackDate());
        self::assertFalse($flags->hasAmbiguousTimezone());
    }

    /**
     * Verifies that unreliable files return the concrete fallback and timezone
     * flags reported by the strategy.
     */
    #[Test]
    public function resolveReturnsFlagsForUnreliableFile(): void
    {
        $file     = new SplFileInfo('/tmp/2024-01-15.mov');
        $strategy = self::createStub(MetadataAwareRenameStrategyInterface::class);

        $strategy
            ->method('hasReliableDateTime')
            ->willReturnStrictMap([[$file, false]]);
        $strategy
            ->method('isFallbackDateTime')
            ->willReturnStrictMap([[$file, false]]);
        $strategy
            ->method('isAmbiguousTimezone')
            ->willReturnStrictMap([[$file, true]]);

        $flags = MetadataQualityFlagResolver::resolve($file, $strategy);

        self::assertFalse($flags->hasFallbackDate());
        self::assertTrue($flags->hasAmbiguousTimezone());
    }
}
