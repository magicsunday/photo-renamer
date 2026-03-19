<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use SplFileInfo;

/**
 * Extended rename strategy that exposes metadata quality indicators for
 * the duplicate detection pipeline. Allows the pipeline to track fallback
 * dates and ambiguous timezones without depending on a concrete strategy class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface MetadataAwareRenameStrategyInterface extends RenameStrategyInterface
{
    /**
     * Returns whether the given file's capture date came from the fallback
     * DateTime tag (0x0132) instead of DateTimeOriginal or CreateDate.
     *
     * @param SplFileInfo $splFileInfo File to query
     *
     * @return bool True when the date came from the fallback tag
     */
    public function isFallbackDateTime(SplFileInfo $splFileInfo): bool;

    /**
     * Returns whether the given file has an ambiguous timezone
     * (cannot determine if the QuickTime timestamp is UTC or local time).
     *
     * @param SplFileInfo $splFileInfo File to query
     *
     * @return bool True when the timezone is ambiguous
     */
    public function isAmbiguousTimezone(SplFileInfo $splFileInfo): bool;
}
