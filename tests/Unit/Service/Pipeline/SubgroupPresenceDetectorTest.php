<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Service\Pipeline\SubgroupPresenceDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies subgroup-presence detection for semantic target naming.
 *
 * The detector decides whether TargetNameResolver stays on the flat naming path
 * or switches into subgroup-aware naming, including the important partial
 * classification edge case where some items lost their clusterId.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(SubgroupPresenceDetector::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(Constants::class)]
final class SubgroupPresenceDetectorTest extends TestCase
{
    /**
     * Verifies that duplicate suffixes on clusterIds do not create fake extra subgroups.
     *
     * Two items in the same effective cluster may carry distinct per-file
     * `-duplicate-NNN` suffixes, but naming must still treat them as one subgroup.
     */
    #[Test]
    public function duplicateSuffixesDoNotCountAsSeparateSubgroups(): void
    {
        $detector = new SubgroupPresenceDetector();
        $groupKey = '2024-01-01_12-00-00-000';

        $items = [
            $this->createItemWithCluster('/photos/a.heic', ItemRole::Canonical, $groupKey),
            $this->createItemWithCluster('/photos/b.heic', ItemRole::Duplicate, $groupKey . Constants::DUPLICATE_IDENTIFIER . '001'),
        ];

        self::assertFalse($detector->hasMultipleSubgroups($items));
    }

    /**
     * Verifies that two distinct normalized cluster bases trigger subgroup naming.
     */
    #[Test]
    public function distinctClusterBasesCountAsMultipleSubgroups(): void
    {
        $detector = new SubgroupPresenceDetector();
        $groupKey = '2024-01-01_12-00-00-000';

        $items = [
            $this->createItemWithCluster('/photos/a.heic', ItemRole::Canonical, $groupKey),
            $this->createItemWithCluster('/photos/b.jpg', ItemRole::Duplicate, $groupKey . '-002'),
        ];

        self::assertTrue($detector->hasMultipleSubgroups($items));
    }

    /**
     * Verifies that a mix of classified and unclassified non-companion items is
     * treated as an implicit second subgroup.
     */
    #[Test]
    public function partialClassificationCountsAsMultipleSubgroups(): void
    {
        $detector = new SubgroupPresenceDetector();
        $groupKey = '2024-01-01_12-00-00-000';

        $items = [
            $this->createItemWithCluster('/photos/a.heic', ItemRole::Canonical, $groupKey),
            new AssetItem(new SplFileInfo('/photos/b.heic'), role: ItemRole::Duplicate),
        ];

        self::assertTrue($detector->hasMultipleSubgroups($items));
    }

    /**
     * Verifies that companions are ignored when deciding whether subgroup naming
     * is required for the still-image cluster.
     */
    #[Test]
    public function companionsDoNotCreateSubgroups(): void
    {
        $detector = new SubgroupPresenceDetector();
        $groupKey = '2024-01-01_12-00-00-000';

        $items = [
            $this->createItemWithCluster('/photos/a.heic', ItemRole::Canonical, $groupKey),
            new AssetItem(new SplFileInfo('/photos/a.mov'), role: ItemRole::Companion),
        ];

        self::assertFalse($detector->hasMultipleSubgroups($items));
    }

    /**
     * Builds an AssetItem with a preset clusterId for subgroup-presence tests.
     *
     * @param string   $pathname  Absolute source pathname
     * @param ItemRole $role      Semantic role assigned before naming
     * @param string   $clusterId Raw clusterId produced by SubgroupClassifier
     */
    private function createItemWithCluster(string $pathname, ItemRole $role, string $clusterId): AssetItem
    {
        return new AssetItem(
            new SplFileInfo($pathname),
            role: $role,
            clusterId: $clusterId,
        );
    }
}
