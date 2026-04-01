<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetectorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies Live Photo companion detection within an AssetGroup.
 *
 * Tests content-ID matching (highest priority), basename fallback (when content ID
 * is absent on the candidate), and all safety guards: canonical must have content ID,
 * exactly one basename fallback candidate, conflicting content IDs are flagged.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CompanionDetector::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class CompanionDetectorTest extends TestCase
{
    /**
     * Content-ID match: HEIC(abc) + MOV(abc) should detect MOV as companion.
     */
    #[Test]
    public function contentIdMatchDetectsCompanion(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);

        $companions = $detector->detect($group, $heic);

        self::assertArrayHasKey($mov->file->getPathname(), $companions);
        self::assertCount(1, $companions);
    }

    /**
     * Content-ID match must skip same media type: HEIC(abc) + JPG(abc) should NOT pair.
     */
    #[Test]
    public function contentIdMatchSkipsSameMediaType(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $jpg = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.jpg'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($jpg);

        $companions = $detector->detect($group, $heic);

        self::assertEmpty($companions);
    }

    /**
     * Basename fallback: HEIC(abc) + MOV(no content-id, same basename) should pair.
     */
    #[Test]
    public function basenameFollowsDetectsCompanion(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: null,
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);

        $companions = $detector->detect($group, $heic);

        self::assertArrayHasKey($mov->file->getPathname(), $companions);
        self::assertCount(1, $companions);
    }

    /**
     * Basename fallback with 2+ candidates is ambiguous: no companion detected.
     */
    #[Test]
    public function basenameFollowsRequiresExactlyOneCandidate(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov1 = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: null,
        );

        $mov2 = new AssetItem(
            new SplFileInfo('/other/IMG_0001.mp4'),
            contentIdentifier: null,
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov1);
        $group->addItem($mov2);

        $companions = $detector->detect($group, $heic);

        self::assertEmpty($companions);
    }

    /**
     * Basename fallback with conflicting content ID: HEIC(abc) + MOV(xyz, same basename)
     * should NOT pair and should log a decision.
     */
    #[Test]
    public function basenameFollowsDetectsConflictingContentId(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'xyz',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);

        $companions = $detector->detect($group, $heic);

        self::assertEmpty($companions);
        self::assertNotEmpty($group->getDecisionLog());
        self::assertCount(1, $group->getDecisionLog());
    }

    /**
     * No companion detection when canonical has no content ID.
     */
    #[Test]
    public function noCompanionWhenCanonicalHasNoContentId(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: null,
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: null,
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);

        $companions = $detector->detect($group, $heic);

        self::assertEmpty($companions);
    }

    /**
     * Group with only canonical item returns no companions.
     */
    #[Test]
    public function emptyGroupReturnsNoCompanions(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);

        $companions = $detector->detect($group, $heic);

        self::assertEmpty($companions);
    }

    /**
     * Multiple content-ID companions of same media type: HEIC(abc) + MOV(abc) + MP4(abc)
     * should detect only one video (best candidate), not both.
     */
    #[Test]
    public function multipleContentIdCompanionsSelectsBestPerMediaType(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $mp4 = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mp4'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);
        $group->addItem($mp4);

        $companions = $detector->detect($group, $heic);

        // Only one video companion selected (MOV wins: basename matches canonical)
        self::assertCount(1, $companions);
        self::assertArrayHasKey($mov->file->getPathname(), $companions);
    }

    /**
     * Reversed media types: MOV(abc) as canonical + HEIC(abc) should detect HEIC as companion.
     */
    #[Test]
    public function canonicalIsVideoStillIsCompanion(): void
    {
        $detector = $this->createDetector();

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($mov);
        $group->addItem($heic);

        $companions = $detector->detect($group, $mov);

        self::assertArrayHasKey($heic->file->getPathname(), $companions);
        self::assertCount(1, $companions);
    }

    /**
     * Only one companion per media type when multiple files share the same content-ID.
     * Two MOVs with same content-ID paired to HEIC canonical: only one returned.
     */
    #[Test]
    public function testOnlyOneCompanionPerMediaTypeWhenMultipleShareContentId(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov1 = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $mov2 = new AssetItem(
            new SplFileInfo('/photos/IMG_0002.mov'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov1);
        $group->addItem($mov2);

        $companions = $detector->detect($group, $heic);

        self::assertCount(1, $companions);
    }

    /**
     * Companion whose basename matches canonical's basename is preferred over another.
     */
    #[Test]
    public function testCompanionWithMatchingBasenamePreferedOverOther(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        // MOV with different basename
        $movOther = new AssetItem(
            new SplFileInfo('/photos/IMG_9999.mov'),
            contentIdentifier: 'abc',
        );

        // MOV with matching basename — should be preferred
        $movMatch = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($movOther);
        $group->addItem($movMatch);

        $companions = $detector->detect($group, $heic);

        self::assertCount(1, $companions);
        self::assertArrayHasKey($movMatch->file->getPathname(), $companions);
    }

    /**
     * When no basename match exists, the stable tie-breaker selects the winner:
     * lower clusterRank wins; without clusterRank, shorter pathname wins.
     */
    #[Test]
    public function testCompanionFallbackUsesStableTieBreaker(): void
    {
        $detector = $this->createDetector();

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        // Both MOVs have different basenames from canonical — no basename match
        $movHighRank = new AssetItem(
            new SplFileInfo('/photos/VID_A.mov'),
            contentIdentifier: 'abc',
            clusterRank: 5,
        );

        $movLowRank = new AssetItem(
            new SplFileInfo('/photos/VID_B.mov'),
            contentIdentifier: 'abc',
            clusterRank: 2,
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($movHighRank);
        $group->addItem($movLowRank);

        $companions = $detector->detect($group, $heic);

        // Lower clusterRank wins
        self::assertCount(1, $companions);
        self::assertArrayHasKey($movLowRank->file->getPathname(), $companions);

        // Test without clusterRank: shorter pathname wins
        $movLong = new AssetItem(
            new SplFileInfo('/photos/subdir/VID_LONG.mov'),
            contentIdentifier: 'abc',
        );

        $movShort = new AssetItem(
            new SplFileInfo('/photos/VID_S.mov'),
            contentIdentifier: 'abc',
        );

        $group2 = new AssetGroup('group-2');
        $group2->addItem($heic);
        $group2->addItem($movLong);
        $group2->addItem($movShort);

        $companions2 = $detector->detect($group2, $heic);

        // Shorter pathname wins
        self::assertCount(1, $companions2);
        self::assertArrayHasKey($movShort->file->getPathname(), $companions2);

        // Test lexicographic tie-breaker (same length)
        $movAlpha = new AssetItem(
            new SplFileInfo('/photos/VID_AAA.mov'),
            contentIdentifier: 'abc',
        );

        $movBeta = new AssetItem(
            new SplFileInfo('/photos/VID_BBB.mov'),
            contentIdentifier: 'abc',
        );

        $group3 = new AssetGroup('group-3');
        $group3->addItem($heic);
        $group3->addItem($movBeta);
        $group3->addItem($movAlpha);

        $companions3 = $detector->detect($group3, $heic);

        // Lexicographic: AAA < BBB
        self::assertCount(1, $companions3);
        self::assertArrayHasKey($movAlpha->file->getPathname(), $companions3);
    }

    private function createDetector(): CompanionDetectorInterface
    {
        return new CompanionDetector(
            new MediaTypeClassifier(),
        );
    }
}
