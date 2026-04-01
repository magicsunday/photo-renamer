<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies RenamePlanValidator detects duplicate targets, case-insensitive
 * conflicts, and circular swaps before rename execution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenamePlanValidator::class)]
#[CoversClass(ValidationResult::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
final class RenamePlanValidatorTest extends TestCase
{
    private RenamePlanValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RenamePlanValidator();
    }

    #[Test]
    public function validPlanPassesValidation(): void
    {
        $collection = new AssetGroupCollection();

        $group = new AssetGroup('2024-08-31_14-22-08');
        $item  = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $item  = $item->withProposedName('/photos/2024-08-31_14-22-08.jpg');

        $group->addItem($item);

        $collection->set('2024-08-31_14-22-08', $group);

        $result = $this->validator->validate($collection);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->duplicateTargets);
        self::assertSame([], $result->caseConflicts);
        self::assertSame([], $result->circularSwaps);
    }

    #[Test]
    public function detectsDuplicateTargets(): void
    {
        $collection = new AssetGroupCollection();

        $groupA = new AssetGroup('group-a');
        $itemA  = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $itemA  = $itemA->withProposedName('/photos/2024-08-31_14-22-08.jpg');

        $groupA->addItem($itemA);

        $groupB = new AssetGroup('group-b');
        $itemB  = new AssetItem(new SplFileInfo('/photos/sub/IMG_0002.jpg'));
        $itemB  = $itemB->withProposedName('/photos/2024-08-31_14-22-08.jpg');

        $groupB->addItem($itemB);

        $collection->set('group-a', $groupA);
        $collection->set('group-b', $groupB);

        $result = $this->validator->validate($collection);

        self::assertFalse($result->isValid());
        self::assertContains('/photos/2024-08-31_14-22-08.jpg', $result->duplicateTargets);
    }

    #[Test]
    public function detectsCaseConflicts(): void
    {
        $collection = new AssetGroupCollection();

        $group = new AssetGroup('group-a');
        $itemA = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $itemA = $itemA->withProposedName('/photos/Photo.jpg');

        $group->addItem($itemA);

        $itemB = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));
        $itemB = $itemB->withProposedName('/photos/photo.jpg');

        $group->addItem($itemB);

        $collection->set('group-a', $group);

        $result = $this->validator->validate($collection);

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->caseConflicts);
        self::assertContains('/photos/Photo.jpg', $result->caseConflicts[0]);
        self::assertContains('/photos/photo.jpg', $result->caseConflicts[0]);
    }

    #[Test]
    public function detectsTwoCycleCircularSwaps(): void
    {
        $collection = new AssetGroupCollection();

        $group = new AssetGroup('group-a');
        $itemA = new AssetItem(new SplFileInfo('/photos/A.jpg'));
        $itemA = $itemA->withProposedName('/photos/B.jpg');

        $group->addItem($itemA);

        $itemB = new AssetItem(new SplFileInfo('/photos/B.jpg'));
        $itemB = $itemB->withProposedName('/photos/A.jpg');

        $group->addItem($itemB);

        $collection->set('group-a', $group);

        $result = $this->validator->validate($collection);

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->circularSwaps);

        $swap = $result->circularSwaps[0];

        self::assertContains('/photos/A.jpg', $swap);
        self::assertContains('/photos/B.jpg', $swap);
    }

    #[Test]
    public function detectsThreeCycleCircularSwaps(): void
    {
        $collection = new AssetGroupCollection();

        $group = new AssetGroup('group-a');

        $itemA = new AssetItem(new SplFileInfo('/photos/A.jpg'));
        $itemA = $itemA->withProposedName('/photos/B.jpg');

        $group->addItem($itemA);

        $itemB = new AssetItem(new SplFileInfo('/photos/B.jpg'));
        $itemB = $itemB->withProposedName('/photos/C.jpg');

        $group->addItem($itemB);

        $itemC = new AssetItem(new SplFileInfo('/photos/C.jpg'));
        $itemC = $itemC->withProposedName('/photos/A.jpg');

        $group->addItem($itemC);

        $collection->set('group-a', $group);

        $result = $this->validator->validate($collection);

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->circularSwaps);

        $cycle = $result->circularSwaps[0];

        self::assertContains('/photos/A.jpg', $cycle);
        self::assertContains('/photos/B.jpg', $cycle);
        self::assertContains('/photos/C.jpg', $cycle);
    }

    #[Test]
    public function chainWithoutCycleIsNotCircular(): void
    {
        $collection = new AssetGroupCollection();

        $group = new AssetGroup('group-a');

        // A → B, B → C — no cycle (C does not map back)
        $itemA = new AssetItem(new SplFileInfo('/photos/A.jpg'));
        $itemA = $itemA->withProposedName('/photos/B.jpg');

        $group->addItem($itemA);

        $itemB = new AssetItem(new SplFileInfo('/photos/B.jpg'));
        $itemB = $itemB->withProposedName('/photos/C.jpg');

        $group->addItem($itemB);

        $collection->set('group-a', $group);

        $result = $this->validator->validate($collection);

        self::assertSame([], $result->circularSwaps);
    }

    #[Test]
    public function noOpsAreIgnored(): void
    {
        $collection = new AssetGroupCollection();

        $group = new AssetGroup('group-a');
        $item  = new AssetItem(new SplFileInfo('/photos/already-correct.jpg'));
        $item  = $item->withProposedName('/photos/already-correct.jpg');

        $group->addItem($item);

        $collection->set('group-a', $group);

        $result = $this->validator->validate($collection);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function emptyCollectionIsValid(): void
    {
        $collection = new AssetGroupCollection();

        $result = $this->validator->validate($collection);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function multipleIssuesDetectedSimultaneously(): void
    {
        $collection = new AssetGroupCollection();

        // Duplicate targets: two items targeting the same path
        $groupA = new AssetGroup('group-a');
        $itemA  = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));
        $itemA  = $itemA->withProposedName('/photos/target.jpg');

        $groupA->addItem($itemA);

        $groupB = new AssetGroup('group-b');
        $itemB  = new AssetItem(new SplFileInfo('/photos/IMG_0002.jpg'));
        $itemB  = $itemB->withProposedName('/photos/target.jpg');

        $groupB->addItem($itemB);

        // Case conflicts: Photo.heic vs photo.heic
        $groupC = new AssetGroup('group-c');
        $itemC  = new AssetItem(new SplFileInfo('/photos/IMG_0003.heic'));
        $itemC  = $itemC->withProposedName('/photos/Photo.heic');

        $groupC->addItem($itemC);

        $itemD = new AssetItem(new SplFileInfo('/photos/IMG_0004.heic'));
        $itemD = $itemD->withProposedName('/photos/photo.heic');

        $groupC->addItem($itemD);

        $collection->set('group-a', $groupA);
        $collection->set('group-b', $groupB);
        $collection->set('group-c', $groupC);

        $result = $this->validator->validate($collection);

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->duplicateTargets);
        self::assertNotEmpty($result->caseConflicts);
    }
}
