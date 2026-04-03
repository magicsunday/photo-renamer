<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Renders grouped decision-log entries for both the asset-group and execution-
 * plan branches of the rename pipeline.
 *
 * The console formatting for decision logs is shared between the old
 * `AssetGroupCollection` view and the newer `ExecutionPlan` view. This
 * collaborator keeps that operator-facing formatting in one place so the main
 * renderer no longer has to duplicate the same header/group/entry block logic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class OutputDecisionLogRenderer
{
    /**
     * Renders grouped decision-log entries from the asset-group pipeline view.
     *
     * Groups without any logged decisions are omitted entirely so the console
     * only shows branches where the pipeline recorded explicit reasoning.
     *
     * @param AssetGroupCollection $groups Collection whose groups may carry decision-log lines.
     * @param SymfonyStyle         $io     Console IO used for the formatted decision-log output.
     */
    public function renderAssetGroupDecisionLog(AssetGroupCollection $groups, SymfonyStyle $io): void
    {
        $renderedAnyLog = false;

        foreach ($groups as $group) {
            $log = $group->getDecisionLog();

            if ($log === []) {
                continue;
            }

            $this->renderLogGroup($group->groupKey, $log, $io, $renderedAnyLog);
            $renderedAnyLog = true;
        }

        if ($renderedAnyLog) {
            $io->newLine();
        }
    }

    /**
     * Renders grouped decision-log entries from the execution-plan view.
     *
     * The formatting intentionally matches the asset-group view so operator-
     * facing reasoning remains stable regardless of which runtime model
     * produced the entries.
     *
     * @param ExecutionPlan $plan Execution plan whose groups may carry decision-log lines.
     * @param SymfonyStyle  $io   Console IO used for the formatted decision-log output.
     */
    public function renderExecutionPlanDecisionLog(ExecutionPlan $plan, SymfonyStyle $io): void
    {
        $renderedAnyLog = false;

        foreach ($plan->groups as $group) {
            if ($group->decisionLog === []) {
                continue;
            }

            $this->renderLogGroup($group->groupKey, $group->decisionLog, $io, $renderedAnyLog);
            $renderedAnyLog = true;
        }

        if ($renderedAnyLog) {
            $io->newLine();
        }
    }

    /**
     * Renders one decision-log group including the shared section header on the
     * first emitted group only.
     *
     * @param string       $groupKey       Group identifier shown as the sub-heading.
     * @param list<string> $entries        Human-readable decision-log lines for the group.
     * @param SymfonyStyle $io             Console IO used for output.
     * @param bool         $renderedAnyLog Whether the shared section header has already been emitted.
     */
    private function renderLogGroup(string $groupKey, array $entries, SymfonyStyle $io, bool $renderedAnyLog): void
    {
        if (!$renderedAnyLog) {
            $io->newLine();
            $io->text('<fg=cyan>Decision Log</>');
        }

        $io->text(sprintf('  <fg=yellow>%s</>:', $groupKey));

        foreach ($entries as $entry) {
            $io->text('    ' . $entry);
        }
    }
}
