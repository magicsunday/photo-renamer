<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use SplFileInfo;

/**
 * Resolves the secondary metadata-quality flags that should be exposed when a
 * file is not already considered reliable by the main metadata authority.
 *
 * The resolver intentionally stays below the level of `hasReliableDateTime()`.
 * It does not redefine reliability; it only centralizes the follow-up question
 * "which quality flags remain actionable once reliability has already been
 * checked?" for services that annotate files for later output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class MetadataQualityFlagResolver
{
    /**
     * Resolves which metadata-quality flags should be recorded for the file.
     *
     * Reliable files always return both flags as false, even if the underlying
     * metadata structure still carries fallback or timezone markers. That keeps
     * the caller aligned with `hasReliableDateTime()` as the single source of
     * truth for "does this file still need attention?".
     *
     * @param SplFileInfo                          $file     File to inspect
     * @param MetadataAwareRenameStrategyInterface $strategy Rename strategy exposing quality indicators
     *
     * @return MetadataQualityFlags Actionable quality flags for downstream annotation.
     */
    public static function resolve(
        SplFileInfo $file,
        MetadataAwareRenameStrategyInterface $strategy,
    ): MetadataQualityFlags {
        if ($strategy->hasReliableDateTime($file)) {
            return new MetadataQualityFlags(false, false);
        }

        return new MetadataQualityFlags(
            $strategy->isFallbackDateTime($file),
            $strategy->isAmbiguousTimezone($file),
        );
    }
}
