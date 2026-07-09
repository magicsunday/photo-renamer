<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Pipeline;

/**
 * Captures a conservative cross-group video duplicate finding for later review.
 *
 * Feature Track A must not silently merge every suspicious video pair. This DTO
 * therefore records the exact pair and the reason why the pipeline stopped short
 * of auto-merging it. The fact stays structured inside PipelineContext until a
 * dedicated mapper turns it into output-ready review entries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class VideoDuplicateCandidate
{
    /**
     * @param string $sourcePath      Absolute path of the primary video shown as the review anchor.
     * @param string $counterpartPath Absolute path of the matching cross-group counterpart.
     * @param string $reason          Human-readable explanation of why the pair is review-only.
     */
    public function __construct(
        public string $sourcePath,
        public string $counterpartPath,
        public string $reason,
    ) {
    }
}
