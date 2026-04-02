<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

/**
 * Propagates still-image quality flags to Live Photo companion videos in the legacy pipeline.
 *
 * Live Photos should be reported atomically: when the still image is flagged as
 * ambiguous timezone or fallback date, the paired MOV must inherit the same
 * warning or fallback state. Propagation is intentionally one-way from still to
 * companion because the still remains the authoritative asset for metadata
 * quality decisions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyLivePhotoQualityFlagPropagator
{
    /**
     * Propagates ambiguous-timezone and fallback-date flags from still images to companions.
     *
     * @param list<array{still: string, companion: string}> $livePhotoPairs         Still/companion path pairs collected during duplicate processing.
     * @param array<string, true>                           $ambiguousTimezoneFiles Files currently marked as ambiguous timezone.
     * @param array<string, true>                           $fallbackDateFiles      Files currently marked as fallback date.
     */
    public function propagate(
        array $livePhotoPairs,
        array &$ambiguousTimezoneFiles,
        array &$fallbackDateFiles,
    ): void {
        foreach ($livePhotoPairs as $pair) {
            $stillPath     = $pair['still'];
            $companionPath = $pair['companion'];

            if (isset($ambiguousTimezoneFiles[$stillPath])) {
                $ambiguousTimezoneFiles[$companionPath] = true;
            }

            if (isset($fallbackDateFiles[$stillPath])) {
                $fallbackDateFiles[$companionPath] = true;
            }
        }
    }
}
