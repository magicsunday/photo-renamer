<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Service\Pipeline\PipelineReviewMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies PipelineReviewMapper, which turns structured cross-group video review
 * facts into output-ready review entries for the central renderer.
 *
 * The mapper is the boundary between pipeline-domain facts and console-facing
 * output entries. These tests ensure both paths of the review pair remain visible
 * without forcing RenameOutputRenderer to learn duplicate-policy semantics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PipelineReviewMapper::class)]
#[UsesClass(VideoDuplicateCandidate::class)]
#[UsesClass(OutputEntry::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(PathHelper::class)]
final class PipelineReviewMapperTest extends TestCase
{
    /**
     * Verifies that a structured cross-group video review fact is converted into
     * a review-tagged output entry with relative display paths and a readable
     * counterpart explanation.
     *
     * The mapper must preserve both files involved while staying independent from
     * the concrete console renderer implementation.
     */
    #[Test]
    public function mapVideoDuplicateCandidatesBuildsReviewEntries(): void
    {
        $mapper = new PipelineReviewMapper();

        $entries = $mapper->mapVideoDuplicateCandidates([
            new VideoDuplicateCandidate(
                '/photos/2025/clip.mov',
                '/photos/archive/clip.mov',
                'video stream identical, audio differs',
            ),
        ], '/photos');

        self::assertCount(1, $entries);
        self::assertSame(OutputEntryTag::Review, $entries[0]->tag);
        self::assertSame('/photos/2025/clip.mov', $entries[0]->sortKey);
        self::assertSame('2025/clip.mov', $entries[0]->sourcePath);
        self::assertSame(
            'Cross-group video review: archive/clip.mov — video stream identical, audio differs',
            $entries[0]->reason,
        );
    }
}
