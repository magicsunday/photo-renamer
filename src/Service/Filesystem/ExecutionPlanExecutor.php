<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use RuntimeException;

use function sprintf;

/**
 * Executes runtime `ExecutionPlan` items against the filesystem while tracking
 * occupied paths and last-resort duplicate-suffix fallbacks.
 *
 * This collaborator isolates the plan-specific execution path from the
 * command-facing `FileSystemService` facade. It owns occupied-path indexing,
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
     * @param ProgressReporterInterface    $progressReporter        Reporter used for runtime warnings and recoverable errors.
     * @param RuntimeFileMoveExecutor|null $runtimeFileMoveExecutor Shared runtime mover for concrete filesystem mutations and duplicate-suffix fallback handling.
     */
    public function __construct(
        private ProgressReporterInterface $progressReporter,
        ?RuntimeFileMoveExecutor $runtimeFileMoveExecutor = null,
    ) {
        $this->runtimeFileMoveExecutor = $runtimeFileMoveExecutor
            ?? new RuntimeFileMoveExecutor($this->progressReporter);
    }

    private RuntimeFileMoveExecutor $runtimeFileMoveExecutor;

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

        $executedMoves    = 0;
        $runtimeFallbacks = 0;
        $runtimeErrors    = 0;

        foreach ($plan->groups as $group) {
            foreach ($group->items as $item) {
                if (!$item->isExecutable) {
                    $occupiedPaths[$item->sourcePath] = true;

                    continue;
                }

                try {
                    $actualTarget = $this->moveFileByPath(
                        $item->sourcePath,
                        $item->targetPath,
                        $occupiedPaths,
                        $dryRun,
                    );

                    if (!$dryRun) {
                        ++$executedMoves;

                        if ($actualTarget !== $item->targetPath) {
                            ++$runtimeFallbacks;
                        }
                    }
                } catch (RuntimeException $exception) {
                    $occupiedPaths[$item->sourcePath] = true;
                    $this->progressReporter->error(sprintf('Failed to rename %s: %s', $item->sourcePath, $exception->getMessage()));

                    if (!$dryRun) {
                        ++$runtimeErrors;
                    }
                }
            }
        }

        return new ExecutionResult(
            executedMoves: $executedMoves,
            runtimeFallbacks: $runtimeFallbacks,
            runtimeErrors: $runtimeErrors,
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
