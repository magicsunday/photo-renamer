<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use RuntimeException;

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
            $pairMembers         = [];
            $pendingPairMoves    = 0;
            $successfulPairMoves = 0;
            $readyPairMembers    = 0;

            if ($group->isLivePhotoGroup && !$dryRun) {
                foreach ($group->getLivePhotoComponents() as $item) {
                    $status = 'Not attempted';

                    if ($item->isNoOp) {
                        $status = 'Unchanged (already at target)';
                    } elseif (!$item->isExecutable) {
                        $status = 'Withheld: ' . ($item->executionBlockReason ?? 'not eligible for execution');
                    }

                    $pairMembers[$item->sourcePath] = [
                        'plannedBasename' => pathinfo($item->targetPath, PATHINFO_FILENAME),
                        'currentPath'     => $item->sourcePath,
                        'status'          => $status,
                    ];

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

                        if (isset($pairMembers[$item->sourcePath])) {
                            ++$successfulPairMoves;
                            ++$readyPairMembers;
                            $pairMembers[$item->sourcePath]['currentPath'] = $actualTarget;
                            $pairMembers[$item->sourcePath]['status']      = 'Renamed';
                        }

                        if ($actualTarget !== $item->targetPath) {
                            ++$runtimeFallbacks;
                        }
                    }
                } catch (RuntimeException $exception) {
                    $operationFailed                  = true;
                    $occupiedPaths[$item->sourcePath] = true;
                    $this->progressReporter->error(sprintf('Failed to rename %s: %s', $item->sourcePath, $exception->getMessage()));

                    if (isset($pairMembers[$item->sourcePath])) {
                        $pairMembers[$item->sourcePath]['status'] = 'Failed: ' . $exception->getMessage();
                    }

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
                $separated = $this->hasSeparatedPair($pairMembers);
                $partial   = ($readyPairMembers > 0)
                    && (($successfulPairMoves < $pendingPairMoves) || $separated);
                $pairStatus = 'complete';

                if ($partial) {
                    ++$partialLivePhotoGroups;
                    $pairStatus = 'partial';
                    $this->reportPartialLivePhoto($group, $pairMembers, $separated);
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
     * Detects separation only between components intended to share a basename.
     * Other planned companion variants may legitimately carry their own suffix.
     *
     * @param array<string, array{plannedBasename: string, currentPath: string, status: string}> $members Current component outcomes, scoped to one capture group
     */
    private function hasSeparatedPair(array $members): bool
    {
        $basenamesByPlan = [];

        foreach ($members as $member) {
            $basename = pathinfo($member['currentPath'], PATHINFO_FILENAME);
            $expected = $member['plannedBasename'];

            if (isset($basenamesByPlan[$expected]) && ($basenamesByPlan[$expected] !== $basename)) {
                return true;
            }

            $basenamesByPlan[$expected] = $basename;
        }

        return false;
    }

    /**
     * Reports concrete observed paths and reasons without authorizing recovery writes.
     *
     * @param ExecutionGroup                                                                     $group     Capture containing the incomplete planned pair
     * @param array<string, array{plannedBasename: string, currentPath: string, status: string}> $members   Current component outcomes, retained for this group only
     * @param bool                                                                               $separated Whether members intended to share a basename ended apart
     */
    private function reportPartialLivePhoto(ExecutionGroup $group, array $members, bool $separated): void
    {
        $this->progressReporter->error(sprintf(
            'Live Photo group "%s" incomplete: %s',
            $group->groupKey,
            $separated ? 'paired components have different current basenames.' : 'a component was not processed.',
        ));

        foreach ($group->items as $item) {
            $member = $members[$item->sourcePath] ?? null;

            if ($member === null) {
                continue;
            }

            foreach ([
                $item->type->value . ': ' . $member['status'],
                'Source: ' . $item->sourcePath,
                'Planned: ' . $item->targetPath,
                'Current: ' . $member['currentPath'],
            ] as $line) {
                $this->progressReporter->text(ConsoleText::escape($line));
            }
        }

        $journalPath = $this->mutationJournal->pathname();

        if ($journalPath !== null) {
            $this->progressReporter->text('Journal: ' . ConsoleText::escape($journalPath));
        }

        $this->progressReporter->text('Keep all components. Resolve the reported cause, preview again, then confirm a fresh run. No automatic rollback was performed.');
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
