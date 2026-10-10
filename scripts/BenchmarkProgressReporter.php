<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Benchmark;

use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;

use function memory_get_usage;
use function str_contains;

/**
 * Counts emitted cross-group comparison plans without retaining comparisons or
 * terminal output. This benchmark-only observer leaves actual matching intact.
 */
final class BenchmarkProgressReporter implements ProgressReporterInterface
{
    /** Whether the current progress belongs to cross-group reconciliation. */
    private bool $crossGroup = false;

    /** Number of comparison plans consumed by the real reconciler. */
    public int $comparisonPlans = 0;

    /** PHP bytes when the first lazy comparison reached the reconciler. */
    public ?int $firstComparisonBytes = null;

    /**
     * Records the first streamed comparison's allocation footprint.
     *
     * @param int $max Reported workload size, unused by the bounded observer
     *
     * @return void
     */
    public function startProgress(int $max): void
    {
        if ($this->crossGroup && ($this->firstComparisonBytes === null)) {
            $this->firstComparisonBytes = memory_get_usage(true);
        }
    }

    /**
     * Counts only plans consumed in the reconciliation phase.
     *
     * @param int $step Number of completed plans
     *
     * @return void
     */
    public function advance(int $step = 1): void
    {
        if ($this->crossGroup) {
            $this->comparisonPlans += $step;
        }
    }

    /**
     * Ends the observed phase without retaining progress history.
     *
     * @return void
     */
    public function finish(): void
    {
        $this->crossGroup = false;
    }

    /**
     * Discards informational output to avoid measuring buffered terminal text.
     *
     * @param string $message Unretained informational message
     *
     * @return void
     */
    public function text(string $message): void
    {
    }

    /**
     * Discards diagnostics; the worker separately validates its final result.
     *
     * @param string $message Unretained diagnostic
     *
     * @return void
     */
    public function error(string $message): void
    {
    }

    /**
     * Discards pair diagnostics instead of constructing a comparison history.
     *
     * @param string $message Unretained pair diagnostic
     *
     * @return void
     */
    public function debug(string $message): void
    {
    }

    /**
     * Selects reconciliation progress using its existing phase heading.
     *
     * @param string $title Existing service phase heading
     *
     * @return void
     */
    public function section(string $title): void
    {
        $this->crossGroup = str_contains($title, 'Reconciling cross-group videos');
    }
}
