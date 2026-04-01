<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the CanonicalScorerInterface contract implemented by CanonicalScorer.
 *
 * The scorer computes a weighted priority score for each AssetItem in an AssetGroup.
 * Format priority is the dominant signal — a preferred format (HEIC) always beats
 * a correctly-named lower-priority format (JPG).
 *
 * Note: assertGreaterThan($a, $b) asserts $b > $a (PHPUnit convention: expected, actual).
 * Higher priorityScore = more likely to be selected as canonical. The assertions below
 * are correct — the item expected to win canonical selection has the higher score.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CanonicalScorer::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(FileHelper::class)]
final class CanonicalScorerTest extends TestCase
{
    /**
     * Creates a scorer with default format priority ['heic', 'jpg'] and source dir '/photos'.
     */
    private function createScorer(): CanonicalScorerInterface
    {
        $scorer = new CanonicalScorer();
        $scorer->setFormatPriority(['heic', 'jpg']);
        $scorer->setSourceDirectory('/photos');

        return $scorer;
    }

    /**
     * HEIC should score higher than JPG due to format priority ordering.
     */
    #[Test]
    public function heicWinsOverJpgByFormatPriority(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0002.heic')));

        $scorer->scoreItems($group);

        $items = $group->getItems();
        $jpg   = $items[0];
        $heic  = $items[1];

        // Higher score = canonical winner; assertGreaterThan(loser, winner)
        self::assertGreaterThan($jpg->priorityScore, $heic->priorityScore);
    }

    /**
     * A correctly-named JPG (idempotent) must still lose to an unnamed HEIC
     * because format priority is the dominant signal.
     */
    #[Test]
    public function formatPriorityDominatesIdempotency(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/2024-08-31_14-22-08-123.jpg')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.heic')));

        $scorer->scoreItems($group);

        $items = $group->getItems();
        $jpg   = $items[0];
        $heic  = $items[1];

        // JPG: format (2-1)*10000=10000 + idempotency 1000 + root 50 = 11050 + tie-break
        // HEIC: format (2-0)*10000=20000 + root 50 = 20050 + tie-break
        // Higher score = canonical winner; assertGreaterThan(loser, winner)
        self::assertGreaterThan($jpg->priorityScore, $heic->priorityScore);
    }

    /**
     * Within the same format, the idempotent file (already correctly named) wins.
     */
    #[Test]
    public function idempotencyWinsWithinSameFormat(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.heic')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/2024-08-31_14-22-08-123.heic')));

        $scorer->scoreItems($group);

        $items      = $group->getItems();
        $unnamed    = $items[0];
        $idempotent = $items[1];

        // Higher score = canonical winner; assertGreaterThan(loser, winner)
        self::assertGreaterThan($unnamed->priorityScore, $idempotent->priorityScore);
    }

    /**
     * A file in the root (source) directory gets a bonus over a file in a subdirectory.
     */
    #[Test]
    public function rootDirectoryBonusApplied(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/subdir/IMG_0001.heic')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0002.heic')));

        $scorer->scoreItems($group);

        $items  = $group->getItems();
        $subdir = $items[0];
        $root   = $items[1];

        // Higher score = canonical winner; assertGreaterThan(loser, winner)
        self::assertGreaterThan($subdir->priorityScore, $root->priorityScore);
    }

    /**
     * An item with a content identifier (Live Photo) gets a bonus.
     */
    #[Test]
    public function livePhotoIdBonusApplied(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.heic')));
        $group->addItem(new AssetItem(
            new SplFileInfo('/photos/IMG_0002.heic'),
            contentIdentifier: 'ABC-123',
        ));

        $scorer->scoreItems($group);

        $items      = $group->getItems();
        $noLiveId   = $items[0];
        $withLiveId = $items[1];

        // Higher score = canonical winner; assertGreaterThan(loser, winner)
        self::assertGreaterThan($noLiveId->priorityScore, $withLiveId->priorityScore);
    }

    /**
     * selectCanonical returns the item with the highest priority score.
     */
    #[Test]
    public function selectCanonicalReturnsHighestScored(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0002.heic')));

        $scorer->scoreItems($group);

        $canonical = $scorer->selectCanonical($group);

        self::assertNotNull($canonical);
        self::assertSame('heic', $canonical->file->getExtension());
    }

    /**
     * selectCanonical returns null for an empty group.
     */
    #[Test]
    public function emptyGroupReturnsNull(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('empty');

        self::assertNull($scorer->selectCanonical($group));
    }

    /**
     * Format priority matching is case-insensitive: scorer set with 'HEIC',
     * item has '.heic' extension — format score should still apply.
     */
    #[Test]
    public function formatPriorityIsCaseInsensitive(): void
    {
        $scorer = new CanonicalScorer();
        $scorer->setFormatPriority(['HEIC', 'JPG']);
        $scorer->setSourceDirectory('/photos');

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.heic')));

        $scorer->scoreItems($group);

        $items = $group->getItems();

        // Format score: (2-0)*10000 = 20000, plus root 50 + tie-break
        self::assertGreaterThan(10000, $items[0]->priorityScore);
    }

    /**
     * A .png file (not in the priority list) gets zero format score.
     */
    #[Test]
    public function unknownFormatGetsZeroFormatScore(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.png')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0002.heic')));

        $scorer->scoreItems($group);

        $items = $group->getItems();
        $png   = $items[0];
        $heic  = $items[1];

        // PNG format score = 0, HEIC format score = 20000
        // Higher score = canonical winner; assertGreaterThan(loser, winner)
        self::assertGreaterThan($png->priorityScore, $heic->priorityScore);
        // PNG should have less than 10000 (no format bonus)
        self::assertLessThan(10000, $png->priorityScore);
    }

    /**
     * When everything else is equal, the shorter pathname gets a slightly higher score.
     */
    #[Test]
    public function tieBreakFavorsShorterPath(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/a-very-long-directory-name/IMG_0001.heic')));
        $group->addItem(new AssetItem(new SplFileInfo('/photos/short/IMG.heic')));

        $scorer->scoreItems($group);

        $items     = $group->getItems();
        $longPath  = $items[0];
        $shortPath = $items[1];

        // Higher score = canonical winner; assertGreaterThan(loser, winner)
        self::assertGreaterThan($longPath->priorityScore, $shortPath->priorityScore);
    }

    /**
     * After scoreItems(), the items in the group have updated priorityScore and reasoning.
     */
    #[Test]
    public function scoreItemsMutatesGroupInPlace(): void
    {
        $scorer = $this->createScorer();

        $group = new AssetGroup('2024-08-31_14-22-08-123');
        $group->addItem(new AssetItem(new SplFileInfo('/photos/IMG_0001.heic')));

        // Before scoring, priorityScore defaults to 0
        self::assertSame(0, $group->getItems()[0]->priorityScore);
        self::assertSame([], $group->getItems()[0]->reasoning);

        $scorer->scoreItems($group);

        $scored = $group->getItems()[0];

        self::assertGreaterThan(0, $scored->priorityScore);
        self::assertNotEmpty($scored->reasoning);
    }
}
