<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use Override;
use RuntimeException;
use SplFileInfo;

use function dirname;
use function pathinfo;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * Makes all proposed names unique against the disk index and already-planned
 * targets. Each resolved target is marked as occupied in the PipelineContext
 * so that later items in the same or subsequent groups see it.
 *
 * Pure logic — no constructor dependencies.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CollisionResolver implements CollisionResolverInterface
{
    /**
     * Iterates all items with a proposed name, claiming free targets and resolving
     * occupied ones by appending incrementing duplicate suffixes.
     *
     * @param AssetGroupCollection $groups  Groups with proposed names to deduplicate
     * @param PipelineContext      $context Pipeline state tracking occupied paths and collision count
     */
    #[Override]
    public function resolve(
        AssetGroupCollection $groups,
        PipelineContext $context,
    ): void {
        foreach ($groups as $group) {
            // Build a set of source paths that will be freed by renames within this
            // group. Only items that are actually being renamed (source !== target)
            // free their source path. No-op items stay in place — their path remains
            // occupied and must not be treated as reclaimable.
            /** @var array<string, true> $groupSourcePaths */
            $groupSourcePaths = [];

            foreach ($group->getItems() as $item) {
                if (($item->proposedName !== null) && ($item->proposedName !== $item->file->getPathname())) {
                    $groupSourcePaths[$item->file->getPathname()] = true;
                }
            }

            foreach ($group->getItems() as $item) {
                if ($item->proposedName === null) {
                    continue;
                }

                // File is already at its target path — mark occupied, no rename needed
                if ($item->proposedName === $item->file->getPathname()) {
                    $context->markOccupied($item->proposedName);

                    continue;
                }

                // Target is free, or occupied by another file in the same group
                // (which will be renamed away). Either way, claim it.
                if (
                    !$context->isOccupied($item->proposedName)
                    || isset($groupSourcePaths[$item->proposedName])
                ) {
                    $context->markOccupied($item->proposedName);

                    continue;
                }

                // Target is occupied by a file outside this group — find a unique alternative
                $resolved = $this->findUniquePath(
                    $item->proposedName,
                    $item->file->getPathname(),
                    $context,
                );

                $group->replaceItem($item, $item->withProposedName($resolved));
                $context->markOccupied($resolved);
                $context->incrementNamingCollisions();
            }
        }
    }

    /**
     * Finds a unique path by appending incrementing -duplicate-NNN suffixes.
     * Returns the source path itself when a candidate matches it (idempotent rename).
     *
     * @param string          $proposedName Originally proposed target pathname
     * @param string          $sourcePath   Current pathname of the source file
     * @param PipelineContext $context      Pipeline state for occupied-path lookups
     *
     * @return string Unique target pathname
     *
     * @throws RuntimeException When the maximum duplicate suffix is exceeded
     */
    private function findUniquePath(
        string $proposedName,
        string $sourcePath,
        PipelineContext $context,
    ): string {
        $directory = dirname($proposedName);
        $extension = pathinfo($proposedName, PATHINFO_EXTENSION);
        $baseName  = FileHelper::basenameWithoutExtension(new SplFileInfo($proposedName));
        $baseName  = FileHelper::stripDuplicateSuffix($baseName);

        $counter = 1;

        while ($counter <= Constants::MAX_DUPLICATE_SUFFIX) {
            $candidate = sprintf(
                '%s%s%s%s%03d.%s',
                $directory,
                DIRECTORY_SEPARATOR,
                $baseName,
                Constants::DUPLICATE_IDENTIFIER,
                $counter,
                $extension,
            );

            // Idempotent: the candidate matches the source file's current path
            if ($candidate === $sourcePath) {
                return $candidate;
            }

            if (!$context->isOccupied($candidate)) {
                return $candidate;
            }

            ++$counter;
        }

        throw new RuntimeException(
            sprintf('Max duplicate suffix (%d) exceeded for: %s', Constants::MAX_DUPLICATE_SUFFIX, $proposedName),
        );
    }
}
