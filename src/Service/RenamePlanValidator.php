<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;

use function array_key_exists;
use function array_keys;
use function array_search;
use function array_slice;
use function count;
use function in_array;
use function strtolower;

/**
 * Validates a rename plan before execution. Detects duplicate targets,
 * case-insensitive conflicts, and circular swaps across an entire
 * AssetGroupCollection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenamePlanValidator
{
    /**
     * Validates the rename plan encoded in the given collection.
     *
     * Iterates all items with a proposedName set, skipping no-ops
     * (where source path equals target path), and checks for:
     *
     * - Duplicate targets: multiple source files mapping to the same target path
     * - Case conflicts: targets that differ only in letter casing
     * - Circular swaps: A->B and B->A two-cycle renames
     *
     * @param AssetGroupCollection $groups Collection of asset groups to validate
     *
     * @return ValidationResult Detected issues (empty when the plan is valid)
     */
    public function validate(AssetGroupCollection $groups): ValidationResult
    {
        /** @var array<string, list<string>> $targetToSources */
        $targetToSources = [];

        /** @var array<string, list<string>> $caseMap */
        $caseMap = [];

        /** @var array<string, string> $sourceToTarget */
        $sourceToTarget = [];

        /** @var AssetGroup $group */
        foreach ($groups as $group) {
            foreach ($group->getItems() as $item) {
                if ($item->proposedName === null) {
                    continue;
                }

                $source = $item->file->getPathname();
                $target = $item->proposedName;

                // Skip no-ops
                if ($source === $target) {
                    continue;
                }

                // Track target -> sources for duplicate detection
                if (!array_key_exists($target, $targetToSources)) {
                    $targetToSources[$target] = [];
                }

                $targetToSources[$target][] = $source;

                // Track lowercase target -> original-case targets for case conflict detection
                $lowerTarget = strtolower($target);

                if (!array_key_exists($lowerTarget, $caseMap)) {
                    $caseMap[$lowerTarget] = [];
                }

                if (!$this->inArray($target, $caseMap[$lowerTarget])) {
                    $caseMap[$lowerTarget][] = $target;
                }

                // Track source -> target for circular swap detection
                $sourceToTarget[$source] = $target;
            }
        }

        $duplicateTargets = $this->detectDuplicateTargets($targetToSources);
        $caseConflicts    = $this->detectCaseConflicts($caseMap);
        $circularSwaps    = $this->detectCircularSwaps($sourceToTarget);

        return new ValidationResult($duplicateTargets, $caseConflicts, $circularSwaps);
    }

    /**
     * Detects targets that have more than one source file.
     *
     * @param array<string, list<string>> $targetToSources
     *
     * @return list<string>
     */
    private function detectDuplicateTargets(array $targetToSources): array
    {
        $duplicates = [];

        foreach ($targetToSources as $target => $sources) {
            if (count($sources) > 1) {
                $duplicates[] = $target;
            }
        }

        return $duplicates;
    }

    /**
     * Detects targets that differ only in letter casing.
     *
     * @param array<string, list<string>> $caseMap
     *
     * @return list<list<string>>
     */
    private function detectCaseConflicts(array $caseMap): array
    {
        $conflicts = [];

        foreach ($caseMap as $targets) {
            if (count($targets) > 1) {
                $conflicts[] = $targets;
            }
        }

        return $conflicts;
    }

    /**
     * Detects all rename cycles (2-cycles, 3-cycles, etc.) using DFS.
     *
     * Follows rename chains from each unvisited node. When a node already
     * on the current DFS stack is reached again, the cycle is extracted.
     *
     * @param array<string, string> $sourceToTarget
     *
     * @return list<list<string>>
     */
    private function detectCircularSwaps(array $sourceToTarget): array
    {
        /** @var list<list<string>> $circularSwaps */
        $circularSwaps = [];

        /** @var array<string, true> $visited */
        $visited = [];

        /** @var array<string, true> $inStack */
        $inStack = [];

        foreach (array_keys($sourceToTarget) as $start) {
            if (isset($visited[$start])) {
                continue;
            }

            // Follow the chain from $start
            /** @var list<string> $path */
            $path    = [];
            $current = $start;

            while ($current !== null && !isset($visited[$current])) {
                if (isset($inStack[$current])) {
                    // Found a cycle — extract it from $path
                    $cycleStart = array_search($current, $path, true);

                    if ($cycleStart !== false) {
                        $cycle           = array_slice($path, $cycleStart);
                        $cycle[]         = $current; // close the cycle
                        $circularSwaps[] = $cycle;
                    }

                    break;
                }

                $inStack[$current] = true;
                $path[]            = $current;
                $current           = $sourceToTarget[$current] ?? null;
            }

            // Mark all nodes in this path as visited
            foreach ($path as $node) {
                $visited[$node] = true;
                unset($inStack[$node]);
            }
        }

        return $circularSwaps;
    }

    /**
     * Checks whether a value exists in an array (strict comparison).
     *
     * @param string       $needle Value to search for
     * @param list<string> $array  Array to search in
     */
    private function inArray(string $needle, array $array): bool
    {
        return in_array($needle, $array, true);
    }
}
