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
 * Keeps the exact source pair and review reason in PipelineContext for output.
 * A candidate is review-only and does not authorize duplicate grouping or deletion.
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
