<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Model\AssetItem;

/**
 * Immutable duration-bucket entry used by the cross-group video reconciler.
 *
 * The reconciler first groups candidate videos by normalized duration before it
 * plans any stream-level comparisons. This DTO makes the per-entry association
 * between capture-group key and candidate item explicit instead of carrying it as
 * an anonymous array-shape.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class DurationBucketedVideoCandidate
{
    /**
     * @param string    $groupKey Capture-group key that currently owns the candidate item
     * @param AssetItem $item     Candidate video item inside the duration bucket
     */
    public function __construct(
        public string $groupKey,
        public AssetItem $item,
    ) {
    }
}
