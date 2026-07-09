<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Verify;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Service\Verify\LivePhotoCompletenessAnalyzer;
use MagicSunday\Renamer\Service\Verify\LivePhotoContentIdMap;
use MagicSunday\Renamer\Service\Verify\LivePhotoContentIdObservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Live Photo completeness analyzer that converts grouped content-identifier
 * observations into actionable "missing companion" findings for verify mode.
 *
 * The analyzer must only emit findings when a content identifier exists on exactly one
 * media family within a directory. Complete still+video pairs must stay silent, while
 * orphan stills and orphan videos receive an explicit path-local message.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LivePhotoCompletenessAnalyzer::class)]
#[UsesClass(LivePhotoContentIdMap::class)]
#[UsesClass(LivePhotoContentIdObservation::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(PathHelper::class)]
final class LivePhotoCompletenessAnalyzerTest extends TestCase
{
    /**
     * Verifies that a complete still+video Live Photo pair with the same content
     * identifier produces no findings.
     */
    #[Test]
    public function completeLivePhotoPairProducesNoFindings(): void
    {
        $analyzer     = new LivePhotoCompletenessAnalyzer();
        $contentIdMap = new LivePhotoContentIdMap();
        $contentIdMap->add('/photos', 'uuid-1', new LivePhotoContentIdObservation('/photos/IMG_0001.heic', true));
        $contentIdMap->add('/photos', 'uuid-1', new LivePhotoContentIdObservation('/photos/IMG_0001.mov', false));

        $findings = $analyzer->analyze(
            $contentIdMap,
            '/photos',
        );

        self::assertSame([], $findings);
    }

    /**
     * Verifies that an orphan MOV with no still companion is reported with the
     * expected verify-mode repair hint.
     */
    #[Test]
    public function orphanVideoProducesMissingStillFinding(): void
    {
        $analyzer     = new LivePhotoCompletenessAnalyzer();
        $contentIdMap = new LivePhotoContentIdMap();
        $contentIdMap->add('/photos', 'uuid-1', new LivePhotoContentIdObservation('/photos/IMG_0042.mov', false));

        $findings = $analyzer->analyze(
            $contentIdMap,
            '/photos',
        );

        self::assertSame(
            ['IMG_0042.mov → no paired JPG/HEIC'],
            $findings,
        );
    }
}
