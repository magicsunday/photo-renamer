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
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Service\Pipeline\ExistingSubgroupNamePreserver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies degraded subgroup-name preservation as an isolated naming policy.
 *
 * The preserver may only keep current filenames when the degraded group still
 * forms a coherent prior subgroup result; otherwise it must decline so normal
 * naming can continue.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExistingSubgroupNamePreserver::class)]
final class ExistingSubgroupNamePreserverTest extends TestCase
{
    /**
     * Verifies that a degraded group with coherent subgroup-style names keeps
     * those existing names instead of flattening to duplicate suffixes.
     */
    #[Test]
    public function preserveIfPossibleKeepsCoherentExistingSubgroupNames(): void
    {
        $preserver = new ExistingSubgroupNamePreserver();
        $group     = $this->createDegradedGroup('2024-01-01_12-00-00-000', [
            $this->createItem('/photos/2024-01-01_12-00-00-000.heic', ItemRole::Canonical),
            $this->createItem('/photos/2024-01-01_12-00-00-000-002.jpg', ItemRole::Duplicate),
            $this->createItem('/photos/2024-01-01_12-00-00-000-003.jpg', ItemRole::Duplicate),
        ]);

        self::assertTrue($preserver->preserveIfPossible(
            $group,
            $group->getItems(),
            'heic',
            false,
            static fn (AssetItem $item, string $canonicalExtension, bool $useFileExtensionFromSource): string => $canonicalExtension,
        ));

        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $group->getItems()[0]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-002.jpg', $group->getItems()[1]->proposedName);
        self::assertSame('/photos/2024-01-01_12-00-00-000-003.jpg', $group->getItems()[2]->proposedName);
    }

    /**
     * Verifies that conflicting claims on the same clean subgroup basename make
     * preservation fail so the caller can continue with normal subgroup logic.
     */
    #[Test]
    public function preserveIfPossibleRejectsConflictingCleanSubgroupClaims(): void
    {
        $preserver = new ExistingSubgroupNamePreserver();
        $group     = $this->createDegradedGroup('2024-01-01_12-00-00-000', [
            $this->createItem('/photos/2024-01-01_12-00-00-000.heic', ItemRole::Canonical),
            $this->createItem('/photos/2024-01-01_12-00-00-000-002.jpg', ItemRole::Duplicate),
            $this->createItem('/photos/backup/2024-01-01_12-00-00-000-002.jpg', ItemRole::Duplicate),
        ]);

        self::assertFalse($preserver->preserveIfPossible(
            $group,
            $group->getItems(),
            'heic',
            false,
            static fn (AssetItem $item, string $canonicalExtension, bool $useFileExtensionFromSource): string => $canonicalExtension,
        ));
    }

    private function createItem(string $pathname, ItemRole $role): AssetItem
    {
        return new AssetItem(new SplFileInfo($pathname), role: $role);
    }

    /**
     * @param list<AssetItem> $items
     */
    private function createDegradedGroup(string $groupKey, array $items): AssetGroup
    {
        $group = new AssetGroup($groupKey);
        $group->markClassificationFailed('Hash-Fehler');

        foreach ($items as $item) {
            $group->addItem($item);
        }

        return $group;
    }
}
