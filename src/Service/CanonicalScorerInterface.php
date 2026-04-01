<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;

/**
 * Contract for scoring AssetItems within an AssetGroup to determine which file
 * becomes the canonical representative. Format priority is the dominant signal.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface CanonicalScorerInterface
{
    /**
     * Set the ordered format priority list (highest priority first).
     *
     * @param list<string> $formatPriority Lowercase extensions ordered by descending preference
     */
    public function setFormatPriority(array $formatPriority): void;

    /**
     * Set the source directory for root-directory bonus scoring.
     *
     * @param string $sourceDirectory Absolute path to the directory being processed
     */
    public function setSourceDirectory(string $sourceDirectory): void;

    /**
     * Score all items in the group in-place via replaceItem().
     *
     * @param AssetGroup $group Group whose items will be scored
     */
    public function scoreItems(AssetGroup $group): void;

    /**
     * Return the highest-scored item, or null if the group is empty.
     *
     * @param AssetGroup $group Group to select from
     *
     * @return AssetItem|null Highest-scored item, or null when group is empty
     */
    public function selectCanonical(AssetGroup $group): ?AssetItem;
}
