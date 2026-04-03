<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use Closure;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\ItemRole;

use function array_unique;
use function count;
use function preg_match;
use function preg_quote;
use function sort;

use const DIRECTORY_SEPARATOR;

/**
 * Preserves existing subgroup-style names when degraded classification proves a
 * prior successful subgroup run should remain authoritative.
 *
 * This is the narrowly bounded exception in the naming pipeline where current
 * filenames may influence behavior: only classification-degraded groups may
 * keep their current subgroup names, and only when strict structural checks
 * show those names look like a coherent result of an earlier successful run.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class ExistingSubgroupNamePreserver
{
    /**
     * Attempts to preserve current subgroup names for a degraded group.
     *
     * @param AssetGroup                               $group                      Degraded group whose current filenames may be preserved.
     * @param list<AssetItem>                          $items                      All items in the group.
     * @param string                                   $canonicalExtension         Normalized extension of the canonical item.
     * @param bool                                     $useFileExtensionFromSource Whether to preserve source extension outside preserved subgroup items.
     * @param Closure(AssetItem, string, bool): string $resolveExtension           Resolves target extension for non-subgroup items that still use normal extension rules.
     */
    public function preserveIfPossible(
        AssetGroup $group,
        array $items,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
        Closure $resolveExtension,
    ): bool {
        $groupKey = $group->groupKey;

        foreach ($items as $item) {
            if ($item->clusterId !== null) {
                return false;
            }
        }

        if (!$this->hasExistingSubgroupPattern($items, $groupKey)) {
            return false;
        }

        $subgroupPattern  = '/^' . preg_quote($groupKey, '/') . '-(\d{3})$/';
        $duplicatePattern = '/^' . preg_quote($groupKey, '/') . '-(\d{3})'
            . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '(\d+)$/';

        /** @var array<string, list<AssetItem>> $subgroupBuckets */
        $subgroupBuckets = [];

        /** @var array<string, list<int>> $subgroupDupNumbers */
        $subgroupDupNumbers = [];

        $hasUnrecognized = false;

        foreach ($items as $item) {
            if ($item->role === ItemRole::Companion) {
                continue;
            }

            $basename = FileHelper::basenameWithoutExtension($item->file);

            if ($basename === $groupKey) {
                $subgroupBuckets[$groupKey][] = $item;

                continue;
            }

            if (preg_match($duplicatePattern, $basename, $matches) === 1) {
                $cleanBasename                        = $groupKey . '-' . $matches[1];
                $subgroupBuckets[$cleanBasename][]    = $item;
                $subgroupDupNumbers[$cleanBasename][] = (int) $matches[2];

                continue;
            }

            if (preg_match($subgroupPattern, $basename) === 1) {
                $subgroupBuckets[$basename][] = $item;

                continue;
            }

            $hasUnrecognized = true;
        }

        if ($hasUnrecognized) {
            return false;
        }

        foreach ($subgroupBuckets as $cleanBasename => $bucketItems) {
            if ($cleanBasename === $groupKey) {
                continue;
            }

            $cleanCount = 0;

            foreach ($bucketItems as $bucketItem) {
                if (FileHelper::basenameWithoutExtension($bucketItem->file) === $cleanBasename) {
                    ++$cleanCount;
                }
            }

            if ($cleanCount > 1) {
                return false;
            }
        }

        foreach ($subgroupDupNumbers as $numbers) {
            $sorted = $numbers;
            sort($sorted);

            if (count($sorted) !== count(array_unique($sorted))) {
                return false;
            }

            foreach ($sorted as $index => $number) {
                if ($number !== $index + 1) {
                    return false;
                }
            }
        }

        $subgroupCleanPattern = '/^' . preg_quote($groupKey, '/') . '-\d{3}/';

        foreach ($items as $item) {
            $basename  = FileHelper::basenameWithoutExtension($item->file);
            $extension = (preg_match($subgroupCleanPattern, $basename) === 1)
                ? FileHelper::normalizeExtension($item->file->getExtension())
                : $resolveExtension($item, $canonicalExtension, $useFileExtensionFromSource);

            $proposedName = $item->file->getPath() . DIRECTORY_SEPARATOR . $basename . '.' . $extension;

            $group->replaceItem($item, $item->withProposedName($proposedName));
        }

        return true;
    }

    /**
     * Returns true when at least one non-canonical, non-companion item already
     * looks like a subgroup or subgroup-duplicate name for the given group key.
     *
     * @param list<AssetItem> $items    All items in the group.
     * @param string          $groupKey Stable group key used as subgroup prefix.
     */
    private function hasExistingSubgroupPattern(array $items, string $groupKey): bool
    {
        $pattern = '/^' . preg_quote($groupKey, '/') . '-\d{3}('
            . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '\d+)?$/';

        foreach ($items as $item) {
            if ($item->role === ItemRole::Canonical) {
                continue;
            }

            if ($item->role === ItemRole::Companion) {
                continue;
            }

            if (preg_match($pattern, FileHelper::basenameWithoutExtension($item->file)) === 1) {
                return true;
            }
        }

        return false;
    }
}
