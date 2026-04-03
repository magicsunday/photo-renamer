<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;

use function sprintf;

/**
 * Maps structured pipeline review facts to output-ready review entries.
 *
 * The pipeline should record review-worthy findings as domain DTOs, not as
 * partially formatted console strings. This mapper is the boundary object that
 * turns those facts into {@see OutputEntry} instances that the central output
 * renderer can append without learning duplicate-policy rules.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class PipelineReviewMapper
{
    /**
     * Maps cross-group video duplicate candidates to output-ready review entries.
     *
     * The resulting entries intentionally anchor the first path on its own line
     * and carry the counterpart path plus the review reason in the secondary text.
     * This keeps the review signal readable in large runs while still showing both
     * files involved.
     *
     * @param list<VideoDuplicateCandidate> $videoDuplicateCandidates Structured review findings from PipelineContext
     * @param string|null                   $sourceBaseDirectory      Base directory used to relativize paths for display
     *
     * @return list<OutputEntry> Output-ready review entries tagged as {@see OutputEntryTag::Review}
     */
    public function mapVideoDuplicateCandidates(array $videoDuplicateCandidates, ?string $sourceBaseDirectory): array
    {
        $entries = [];

        foreach ($videoDuplicateCandidates as $candidate) {
            $entries[] = OutputEntry::info(
                sortKey: $candidate->sourcePath,
                sourcePath: FileHelper::relativizePath($candidate->sourcePath, $sourceBaseDirectory),
                reason: sprintf(
                    'Cross-group video review: %s — %s',
                    FileHelper::relativizePath($candidate->counterpartPath, $sourceBaseDirectory),
                    $candidate->reason,
                ),
                tag: OutputEntryTag::Review,
            );
        }

        return $entries;
    }
}
