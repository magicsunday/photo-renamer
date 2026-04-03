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
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\ItemRole;

use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Resolves the simple flat naming path for groups without multiple subgroups.
 *
 * This collaborator owns the straightforward canonical/companion/duplicate naming
 * rules so TargetNameResolver can focus on deciding which naming path applies to
 * a group rather than constructing the final paths itself.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FlatGroupNameResolver
{
    /**
     * Resolves proposed names for all items within a group that does not require
     * subgroup-aware naming.
     *
     * @param AssetGroup                               $group                      Group to resolve
     * @param list<AssetItem>                          $items                      Items from the group in their current stable order
     * @param string                                   $canonicalExtension         Normalized extension of the canonical item
     * @param bool                                     $useFileExtensionFromSource Whether to preserve source extension
     * @param Closure(AssetItem, string, bool): string $extensionResolver          Callback that resolves extensions consistently with the enclosing TargetNameResolver
     */
    public function resolve(
        AssetGroup $group,
        array $items,
        string $canonicalExtension,
        bool $useFileExtensionFromSource,
        Closure $extensionResolver,
    ): void {
        /** @var array<string, int> $sequenceCounterByExt */
        $sequenceCounterByExt = [];

        foreach ($items as $item) {
            $extension = $extensionResolver(
                $item,
                $canonicalExtension,
                $useFileExtensionFromSource,
            );

            $sequenceCounterByExt[$extension] ??= 0;
            $directory    = $item->file->getPath();
            $proposedName = $this->buildProposedName(
                $directory,
                $group->groupKey,
                $extension,
                $item->role,
                $sequenceCounterByExt[$extension],
            );

            $updated = $item->withProposedName($proposedName);

            if (($item->role === ItemRole::Duplicate) || ($item->role === ItemRole::Ambiguous)) {
                $updated = $updated->withSequenceNumber($sequenceCounterByExt[$extension]);
            }

            $group->replaceItem($item, $updated);
        }
    }

    /**
     * Builds the full proposed pathname from directory, group key, extension, and role.
     *
     * @param string   $directory       Directory part of the target path
     * @param string   $groupKey        Stable group key used as the basename
     * @param string   $extension       Normalized file extension (without leading dot)
     * @param ItemRole $role            Item role determining suffix behavior
     * @param int      $sequenceCounter Running counter for duplicate/ambiguous suffixes (modified by reference)
     */
    private function buildProposedName(
        string $directory,
        string $groupKey,
        string $extension,
        ItemRole $role,
        int &$sequenceCounter,
    ): string {
        return match ($role) {
            ItemRole::Canonical,
            ItemRole::Companion => $this->buildCleanName($directory, $groupKey, $extension),
            ItemRole::Duplicate,
            ItemRole::Ambiguous => $this->buildDuplicateName($directory, $groupKey, ++$sequenceCounter, $extension),
        };
    }

    /**
     * Builds a clean target pathname without any duplicate or subgroup suffix.
     *
     * @param string $directory Directory part of the target path
     * @param string $basename  Base filename (without extension)
     * @param string $extension Normalized file extension (without leading dot)
     */
    private function buildCleanName(string $directory, string $basename, string $extension): string
    {
        return $directory . DIRECTORY_SEPARATOR . $basename . '.' . $extension;
    }

    /**
     * Builds a target pathname with a duplicate suffix (e.g. "key-duplicate-001").
     *
     * @param string $directory       Directory part of the target path
     * @param string $basename        Base filename (without extension)
     * @param int    $duplicateNumber Sequential duplicate number (e.g. 1 → "-duplicate-001")
     * @param string $extension       Normalized file extension (without leading dot)
     */
    private function buildDuplicateName(string $directory, string $basename, int $duplicateNumber, string $extension): string
    {
        return $directory . DIRECTORY_SEPARATOR . sprintf('%s%s%03d', $basename, Constants::DUPLICATE_IDENTIFIER, $duplicateNumber) . '.' . $extension;
    }
}
