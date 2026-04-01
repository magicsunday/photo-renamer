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
     * Multiple content-ID companions: HEIC(abc) + MOV(abc) + MP4(abc) should detect both.
     */
    #[Test]
    public function multipleContentIdCompanionsAllDetected(): void
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

        self::assertArrayHasKey($mov->file->getPathname(), $companions);
        self::assertArrayHasKey($mp4->file->getPathname(), $companions);
        self::assertCount(2, $companions);
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

    private function createDetector(): CompanionDetectorInterface
    {
        return new CompanionDetector(
            new MediaTypeClassifier(),
        );
    }
}
