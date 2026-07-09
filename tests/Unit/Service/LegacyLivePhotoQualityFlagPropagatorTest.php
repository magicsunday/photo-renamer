<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\LegacyLivePhotoPair;
use MagicSunday\Renamer\Service\LegacyLivePhotoQualityFlagPropagator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies one-way quality-flag propagation for legacy Live Photo pairs.
 *
 * The propagator keeps the still image authoritative: fallback and ambiguous
 * timezone flags flow from still to companion video so the pair is tagged
 * consistently, but no reverse propagation is introduced.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyLivePhotoQualityFlagPropagator::class)]
#[UsesClass(LegacyLivePhotoPair::class)]
final class LegacyLivePhotoQualityFlagPropagatorTest extends TestCase
{
    /**
     * Verifies that both ambiguous-timezone and fallback-date flags are copied
     * from the still image to its paired companion video.
     */
    #[Test]
    public function propagateCopiesStillFlagsToCompanion(): void
    {
        $propagator = new LegacyLivePhotoQualityFlagPropagator();
        $pairs      = [
            new LegacyLivePhotoPair('/tmp/IMG_0001.jpg', '/tmp/IMG_0001.mov'),
        ];
        $ambiguousTimezoneFiles = ['/tmp/IMG_0001.jpg' => true];
        $fallbackDateFiles      = ['/tmp/IMG_0001.jpg' => true];

        $propagator->propagate($pairs, $ambiguousTimezoneFiles, $fallbackDateFiles);

        self::assertSame(
            [
                '/tmp/IMG_0001.jpg' => true,
                '/tmp/IMG_0001.mov' => true,
            ],
            $ambiguousTimezoneFiles,
        );
        self::assertSame(
            [
                '/tmp/IMG_0001.jpg' => true,
                '/tmp/IMG_0001.mov' => true,
            ],
            $fallbackDateFiles,
        );
    }

    /**
     * Verifies that flags present only on the companion video do not flow back
     * to the still image.
     */
    #[Test]
    public function propagateDoesNotCopyCompanionFlagsBackToStill(): void
    {
        $propagator = new LegacyLivePhotoQualityFlagPropagator();
        $pairs      = [
            new LegacyLivePhotoPair('/tmp/IMG_0002.jpg', '/tmp/IMG_0002.mov'),
        ];
        $ambiguousTimezoneFiles = ['/tmp/IMG_0002.mov' => true];
        $fallbackDateFiles      = [];

        $propagator->propagate($pairs, $ambiguousTimezoneFiles, $fallbackDateFiles);

        self::assertSame(
            [
                '/tmp/IMG_0002.mov' => true,
            ],
            $ambiguousTimezoneFiles,
        );
        self::assertSame([], $fallbackDateFiles);
    }

    /**
     * Verifies that unrelated pairs remain untouched when the still side does not
     * carry either propagated quality flag.
     */
    #[Test]
    public function propagateLeavesUnflaggedPairsUntouched(): void
    {
        $propagator = new LegacyLivePhotoQualityFlagPropagator();
        $pairs      = [
            new LegacyLivePhotoPair('/tmp/IMG_0003.jpg', '/tmp/IMG_0003.mov'),
        ];
        $ambiguousTimezoneFiles = [];
        $fallbackDateFiles      = [];

        $propagator->propagate($pairs, $ambiguousTimezoneFiles, $fallbackDateFiles);

        self::assertSame([], $ambiguousTimezoneFiles);
        self::assertSame([], $fallbackDateFiles);
    }
}
