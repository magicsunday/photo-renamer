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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Every branch of the resolver, with the secondary flags set so that each one
     * is load-bearing: the reliable row would still pass with the short-circuit
     * removed if the secondaries were left at their default false.
     *
     * @return iterable<string, array{bool, bool, bool, bool, bool}>
     */
    public static function qualityFlagCases(): iterable
    {
        yield 'reliable suppresses both flags' => [true, true, true, false, false];
        yield 'fallback date only' => [false, true, false, true, false];
        yield 'ambiguous timezone only' => [false, false, true, false, true];
        yield 'both quality flags' => [false, true, true, true, true];
    }

    /**
     * Verifies that `hasReliableDateTime()` is the authority — a reliable file
     * suppresses both secondary flags even when the strategy reports them — and
     * that an unreliable file passes each secondary flag through unchanged.
     */
    #[Test]
    #[DataProvider('qualityFlagCases')]
    public function resolveDerivesFlagsFromTheStrategy(
        bool $reliable,
        bool $fallbackDate,
        bool $ambiguousTimezone,
        bool $expectedFallbackDate,
        bool $expectedAmbiguousTimezone,
    ): void {
        $file     = new SplFileInfo('/tmp/2024-01-15.jpg');
        $strategy = self::createStub(MetadataAwareRenameStrategyInterface::class);

        $strategy
            ->method('hasReliableDateTime')
            ->willReturnStrictMap([[$file, $reliable]]);
        $strategy
            ->method('isFallbackDateTime')
            ->willReturnStrictMap([[$file, $fallbackDate]]);
        $strategy
            ->method('isAmbiguousTimezone')
            ->willReturnStrictMap([[$file, $ambiguousTimezone]]);

        $flags = MetadataQualityFlagResolver::resolve($file, $strategy);

        self::assertSame($expectedFallbackDate, $flags->hasFallbackDate());
        self::assertSame($expectedAmbiguousTimezone, $flags->hasAmbiguousTimezone());
    }
}
