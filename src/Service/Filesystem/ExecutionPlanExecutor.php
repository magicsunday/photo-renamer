<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use RuntimeException;

use function array_unique;
use function count;
use function pathinfo;
use function sprintf;

use const PATHINFO_FILENAME;

/**
 * Executes runtime `ExecutionPlan` items against the filesystem while tracking
 * occupied paths and last-resort duplicate-suffix fallbacks.
 *
 * The EXIF command calls this plan-specific executor directly. It owns
 * occupied-path indexing,
 * runtime collision fallback handling, and the resulting `ExecutionResult`
 * counters for the execution-plan branch.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExecutionPlanExecutor
{
    /**
     * @param ProgressReporterInterface $progressReporter        Reporter used for runtime warnings and recoverable errors.
     * @param RuntimeFileMoveExecutor   $runtimeFileMoveExecutor Shared runtime mover for concrete filesystem mutations and duplicate-suffix fallback handling.
     * @param MutationJournal           $mutationJournal         Stops the batch if evidence fails after a successful move
     */
    public function __construct(
        private ProgressReporterInterface $progressReporter,
        private RuntimeFileMoveExecutor $runtimeFileMoveExecutor,
        private MutationJournal $mutationJournal,
    ) {
    }

    /**
     * Executes the provided runtime plan and returns the observed execution
     * counters.
     *
     * Non-executable items keep their source paths occupied so later items do not
     * rename into those locations. Executable items are moved in plan order, with
     * runtime duplicate-suffix fallback applied when a target has become occupied
     * earlier in the same batch.
     *
     * @param ExecutionPlan $plan   Runtime plan to execute.
     * @param bool          $dryRun When true, simulate path transitions without touching the filesystem.
     *
     * @return ExecutionResult Runtime counters for executed moves, fallbacks, and errors.
     */
    public function executePlan(ExecutionPlan $plan, bool $dryRun = false): ExecutionResult
    {
        $occupiedPaths = $this->buildOccupiedPathsFromPlan($plan);

        $executedMoves          = 0;
        $runtimeFallbacks       = 0;
        $runtimeErrors          = 0;
        $partialLivePhotoGroups = 0;
        $stopped                = false;

        foreach ($plan->groups as $group) {
            $pairBasenames       = [];
            $pendingPairMoves    = 0;
            $successfulPairMoves = 0;
            $readyPairMembers    = 0;

            if ($group->isLivePhotoGroup && !$dryRun) {
                foreach ($group->items as $item) {
                    if (($item->type !== ExecutionItemType::Canonical) && ($item->type !== ExecutionItemType::Companion)) {
                        continue;
                    }

                    // Naming preserves each source directory. Matching pair
                    // basenames remain complete even across different folders.
                    $pairBasenames[$item->sourcePath] = pathinfo($item->sourcePath, PATHINFO_FILENAME);

                    if ($item->renameRequired && !$item->isNoOp) {
                        ++$pendingPairMoves;
                    } else {
                        ++$readyPairMembers;
                    }
                }

                try {
                    $this->mutationJournal->planLivePhoto($group);
                } catch (RuntimeException $exception) {
                    ++$runtimeErrors;
                    $this->progressReporter->error($exception->getMessage());

                    break;
                }
            }

            foreach ($group->items as $item) {
                if (!$item->isExecutable) {
                    $occupiedPaths[$item->sourcePath] = true;

                    continue;
                }

                $operationFailed = false;

                try {
                    $actualTarget = $this->moveFileByPath(
                        $item->sourcePath,
                        $item->targetPath,
                        $occupiedPaths,
                        $dryRun,
                    );

                    if (!$dryRun) {
                        ++$executedMoves;

                        if (isset($pairBasenames[$item->sourcePath])) {
                            ++$successfulPairMoves;
                            ++$readyPairMembers;
                            // Naming preserves each source directory. Matching pair
                            // basenames remain complete even across different folders.
                            $pairBasenames[$item->sourcePath] = pathinfo($actualTarget, PATHINFO_FILENAME);
                        }

                        if ($actualTarget !== $item->targetPath) {
                            ++$runtimeFallbacks;
                        }
                    }
                } catch (RuntimeException $exception) {
                    $operationFailed                  = true;
                    $occupiedPaths[$item->sourcePath] = true;
                    $this->progressReporter->error(sprintf('Failed to rename %s: %s', $item->sourcePath, $exception->getMessage()));

                    ++$runtimeErrors;
                }

                if ($this->mutationJournal->failure() !== null) {
                    $this->progressReporter->error($this->mutationJournal->failure());

                    if (!$operationFailed) {
                        ++$runtimeErrors;
                    }

                    $stopped = true;

                    break;
                }
            }

            if ($group->isLivePhotoGroup && !$dryRun) {
                $partial = ($readyPairMembers > 0)
                    && (($successfulPairMoves < $pendingPairMoves) || (count(array_unique($pairBasenames)) > 1));
                $pairStatus = 'complete';

                if ($partial) {
                    ++$partialLivePhotoGroups;
                    $pairStatus = 'partial';
                    $this->progressReporter->error('Live Photo partially processed; inspect its still and companion before retrying.');
                } elseif ($successfulPairMoves < $pendingPairMoves) {
                    $pairStatus = 'unprocessed';
                }

                $this->mutationJournal->livePhotoResult($group->groupKey, $pairStatus);

                if (!$stopped && ($this->mutationJournal->failure() !== null)) {
                    ++$runtimeErrors;
                    $this->progressReporter->error($this->mutationJournal->failure());
                    $stopped = true;
                }
            }

            if ($stopped) {
                break;
            }
        }

        return new ExecutionResult(
            executedMoves: $executedMoves,
            runtimeFallbacks: $runtimeFallbacks,
            runtimeErrors: $runtimeErrors,
            partialLivePhotoGroups: $partialLivePhotoGroups,
        );
    }

    /**
     * Builds an occupied-path index from all source paths currently present in
     * the plan.
     *
     * @param ExecutionPlan $plan Runtime plan whose source paths should be treated as occupied at start.
     *
     * @return array<string, true> Map of absolute occupied pathnames.
     */
    private function buildOccupiedPathsFromPlan(ExecutionPlan $plan): array
    {
        $occupiedPaths = [];

        foreach ($plan->groups as $group) {
            foreach ($group->items as $item) {
                $occupiedPaths[$item->sourcePath] = true;
            }
        }

        return $occupiedPaths;
    }

    /**
     * Moves a single file from source path to target path while respecting the
     * mutable occupied-path index.
     *
     * If the requested target has become occupied by an earlier item in the same
     * run, the executor falls back to the next free duplicate-suffixed path to
     * prevent overwriting files. The occupied-path index is updated even in dry
     * runs so later simulated items see the same path transitions.
     *
     * @param string              $sourcePath    Absolute source file path.
     * @param string              $targetPath    Intended absolute target file path.
     * @param array<string, true> $occupiedPaths Mutable map of currently occupied absolute paths.
     * @param bool                $dryRun        When true, skip actual filesystem writes.
     *
     * @return string Actual target path used after runtime fallback handling.
     */
    private function moveFileByPath(
        string $sourcePath,
        string $targetPath,
        array &$occupiedPaths,
        bool $dryRun,
    ): string {
        return $this->runtimeFileMoveExecutor->moveFileByPath($sourcePath, $targetPath, $occupiedPaths, $dryRun);
    }
}
