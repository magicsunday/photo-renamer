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
 * Describes the exact-content evidence produced by VideoStreamFingerprintMatcher.
 *
 * Feature Track A needs an explicit policy result model instead of ad-hoc booleans
 * spread across the reconciler. This DTO captures whether the video stream matched,
 * how the optional audio streams compare, and which operator-facing review reason
 * applies when the pair is suspicious but not safe to auto-merge.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class VideoFingerprintMatch
{
    /**
     * @param bool        $videoStreamMatched    True when the primary video streams are byte-identical after container metadata is ignored.
     * @param bool        $audioStreamMatched    True when both sides have an audio stream and those streams are byte-identical.
     * @param bool        $bothWithoutAudio      True when neither side exposes an audio stream.
     * @param bool        $missingAudioOnOneSide True when exactly one side lacks audio.
     * @param bool        $audioMismatch         True when both sides have audio but the hashes differ.
     * @param string|null $reviewReason          Human-readable reason used for review-only cases.
     */
    public function __construct(
        public bool $videoStreamMatched,
        public bool $audioStreamMatched,
        public bool $bothWithoutAudio,
        public bool $missingAudioOnOneSide,
        public bool $audioMismatch,
        public ?string $reviewReason = null,
    ) {
    }

    /**
     * Returns true when the pair is safe to auto-merge as an exact duplicate.
     *
     * Exact duplicate policy allows either matching video+audio streams or
     * matching video streams on both-audio-less files. Any asymmetric or mismatched
     * audio situation stays review-only.
     *
     * @return bool True when the matcher produced exact-content duplicate evidence.
     */
    public function isExactDuplicate(): bool
    {
        return $this->videoStreamMatched && ($this->audioStreamMatched || $this->bothWithoutAudio);
    }

    /**
     * Returns true when the pair should be surfaced as a conservative review item.
     *
     * Candidate cases require a matching video stream but stop short of auto-merge
     * because the audio relationship is incomplete or contradictory.
     *
     * @return bool True when the pair is suspicious and requires operator review.
     */
    public function isCandidate(): bool
    {
        return $this->videoStreamMatched && ($this->missingAudioOnOneSide || $this->audioMismatch);
    }
}
