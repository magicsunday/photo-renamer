<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\ItemRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the mutable AssetGroup model, including member management,
 * role-filtered views, decision logging, and format detection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
final class AssetGroupTest extends TestCase
{
    #[Test]
    public function constructorSetsGroupKey(): void
    {
        $group = new AssetGroup('2024-08-31_14-22-08');

        self::assertSame('2024-08-31_14-22-08', $group->groupKey);
        self::assertSame([], $group->getItems());
        self::assertSame([], $group->getDecisionLog());
        self::assertNull($group->getCanonical());
        self::assertSame([], $group->getDuplicates());
        self::assertSame([], $group->getCompanions());
        self::assertSame([], $group->getAmbiguous());
        self::assertSame(0, $group->itemCount());
    }

    #[Test]
    public function addAndRetrieveItems(): void
    {
        $group = new AssetGroup('key-1');
        $item  = new AssetItem(new SplFileInfo('/tmp/photo.heic'));

        $group->addItem($item);

        self::assertSame(1, $group->itemCount());
        self::assertSame([$item], $group->getItems());
    }

    #[Test]
    public function getCanonicalReturnsFirstCanonical(): void
    {
        $group     = new AssetGroup('key-1');
        $duplicate = new AssetItem(new SplFileInfo('/tmp/dup.heic'), ItemRole::Duplicate);
        $canonical = new AssetItem(new SplFileInfo('/tmp/canon.heic'), ItemRole::Canonical);

        $group->addItem($duplicate);
        $group->addItem($canonical);

        self::assertSame($canonical, $group->getCanonical());
    }

    #[Test]
    public function getCanonicalReturnsNullWhenNone(): void
    {
        $group = new AssetGroup('key-1');
        $group->addItem(new AssetItem(new SplFileInfo('/tmp/dup1.heic'), ItemRole::Duplicate));
        $group->addItem(new AssetItem(new SplFileInfo('/tmp/dup2.heic'), ItemRole::Duplicate));

        self::assertNull($group->getCanonical());
    }

    #[Test]
    public function roleFilteringWorksCorrectly(): void
    {
        $group     = new AssetGroup('key-1');
        $canonical = new AssetItem(new SplFileInfo('/tmp/canon.heic'), ItemRole::Canonical);
        $dup1      = new AssetItem(new SplFileInfo('/tmp/dup1.heic'), ItemRole::Duplicate);
        $dup2      = new AssetItem(new SplFileInfo('/tmp/dup2.heic'), ItemRole::Duplicate);
        $companion = new AssetItem(new SplFileInfo('/tmp/comp.mov'), ItemRole::Companion);
        $ambiguous = new AssetItem(new SplFileInfo('/tmp/amb.heic'), ItemRole::Ambiguous);

        $group->addItem($canonical);
        $group->addItem($dup1);
        $group->addItem($dup2);
        $group->addItem($companion);
        $group->addItem($ambiguous);

        self::assertSame($canonical, $group->getCanonical());
        self::assertSame([$dup1, $dup2], $group->getDuplicates());
        self::assertSame([$companion], $group->getCompanions());
        self::assertSame([$ambiguous], $group->getAmbiguous());
    }

    #[Test]
    public function replaceItemUpdatesInPlace(): void
    {
        $group    = new AssetGroup('key-1');
        $original = new AssetItem(new SplFileInfo('/tmp/photo.heic'));

        $group->addItem($original);

        $scored = $original->withScore(42, ['preferred format']);

        $group->replaceItem($original, $scored);

        self::assertSame(1, $group->itemCount());
        self::assertSame(42, $group->getItems()[0]->priorityScore);
        self::assertSame(['preferred format'], $group->getItems()[0]->reasoning);
    }

    #[Test]
    public function replaceItemNoOpsWhenNotFound(): void
    {
        $group   = new AssetGroup('key-1');
        $inGroup = new AssetItem(new SplFileInfo('/tmp/photo.heic'));
        $unknown = new AssetItem(new SplFileInfo('/tmp/other.heic'));

        $group->addItem($inGroup);

        $replacement = $unknown->withScore(99, ['test']);

        $group->replaceItem($unknown, $replacement);

        self::assertSame(1, $group->itemCount());
        self::assertSame($inGroup, $group->getItems()[0]);
    }

    #[Test]
    public function getItemByPathFindsItem(): void
    {
        $group = new AssetGroup('key-1');
        $item  = new AssetItem(new SplFileInfo('/tmp/photo.heic'));

        $group->addItem($item);

        self::assertSame($item, $group->getItemByPath('/tmp/photo.heic'));
        self::assertNull($group->getItemByPath('/tmp/nonexistent.heic'));
    }

    #[Test]
    public function decisionLogAccumulatesEntries(): void
    {
        $group = new AssetGroup('key-1');

        $group->addDecision('Selected canonical by score');
        $group->addDecision('Marked 2 duplicates');

        self::assertSame(
            ['Selected canonical by score', 'Marked 2 duplicates'],
            $group->getDecisionLog(),
        );
    }

    #[Test]
    public function hasMultipleDistinctFormatsDetectsCorrectly(): void
    {
        $group = new AssetGroup('key-1');
        $group->addItem(new AssetItem(new SplFileInfo('/tmp/a.heic')));
        $group->addItem(new AssetItem(new SplFileInfo('/tmp/b.HEIC')));

        self::assertFalse($group->hasMultipleDistinctFormats());

        $group->addItem(new AssetItem(new SplFileInfo('/tmp/c.jpg')));

        self::assertTrue($group->hasMultipleDistinctFormats());
    }

    #[Test]
    public function itemCountReturnsCorrectValue(): void
    {
        $group = new AssetGroup('key-1');

        self::assertSame(0, $group->itemCount());

        $group->addItem(new AssetItem(new SplFileInfo('/tmp/a.heic')));
        $group->addItem(new AssetItem(new SplFileInfo('/tmp/b.jpg')));

        self::assertSame(2, $group->itemCount());
    }

    #[Test]
    public function classificationStateTracking(): void
    {
        $group = new AssetGroup('key-1');

        // Initial state: not attempted
        self::assertFalse($group->wasClassified());
        self::assertFalse($group->isClassificationDegraded());
        self::assertNull($group->getClassificationFailureReason());

        // Mark succeeded
        $group->markClassificationSucceeded();
        self::assertTrue($group->wasClassified());
        self::assertFalse($group->isClassificationDegraded());
        self::assertNull($group->getClassificationFailureReason());

        // Mark failed (overrides previous state)
        $group->markClassificationFailed('hash computation error');
        self::assertTrue($group->wasClassified());
        self::assertTrue($group->isClassificationDegraded());
        self::assertSame('hash computation error', $group->getClassificationFailureReason());
    }

    #[Test]
    public function markClassificationSucceededClearsFailureReason(): void
    {
        $group = new AssetGroup('key-1');

        $group->markClassificationFailed('some error');
        self::assertTrue($group->isClassificationDegraded());
        self::assertSame('some error', $group->getClassificationFailureReason());

        $group->markClassificationSucceeded();
        self::assertFalse($group->isClassificationDegraded());
        self::assertNull($group->getClassificationFailureReason());
    }
}
