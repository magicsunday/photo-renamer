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
use RuntimeException;

use function getrusage;
use function memory_get_peak_usage;
use function memory_get_usage;
use function str_contains;

/**
 * Counts emitted cross-group comparison plans without retaining comparisons or
 * terminal output. This benchmark-only observer leaves actual matching intact.
 */
final class BenchmarkProgressReporter implements ProgressReporterInterface
{
    /**
     * Fixed phase measurements; never retain per-file or per-pair history.
     *
     * @var array<string, array{php_current_bytes: int, php_allocated_bytes: int, php_peak_bytes: int, peak_rss_bytes: int}>
     */
    public array $measurements = [];

    /** Current finite service phase used to label completion checkpoints. */
    private string $phase = 'idle';

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
        $this->measure($this->phase . '_finished');
        $this->crossGroup = false;
    }

    /**
     * Observes only the fixed scan heading without buffering terminal text.
     *
     * @param string $message Unretained informational message
     *
     * @return void
     */
    public function text(string $message): void
    {
        if (str_contains($message, 'Scanning files')) {
            $this->phase = 'group_scan';
            $this->measure('file_list_and_indexes');
        }
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

        if (str_contains($title, 'Pairing Live Photos')) {
            $this->phase = 'pairing';
            $this->measure('pairing_bridge');
        } elseif (str_contains($title, 'Classifying subgroups')) {
            $this->phase = 'classification';
            $this->measure('groups_built');
        } elseif ($this->crossGroup) {
            $this->phase = 'cross_group';
            $this->measure('before_cross_group');
        }
    }

    /**
     * Records live PHP allocation and cumulative peaks at a finite checkpoint.
     * Existing reporting headings expose group/classification lifetimes without
     * production profiling interfaces; the worker adds cache/plan/output points.
     * Linux ru_maxrss is KiB, while JSON records bytes consistently.
     *
     * @param string $stage Fixed benchmark checkpoint, never a filename or pair key
     *
     * @return void
     */
    public function measure(string $stage): void
    {
        $usage = getrusage();

        if ($usage === false) {
            throw new RuntimeException('Linux worker RSS measurement is unavailable.');
        }

        $this->measurements[$stage] = [
            'php_current_bytes'   => memory_get_usage(false),
            'php_allocated_bytes' => memory_get_usage(true),
            'php_peak_bytes'      => memory_get_peak_usage(true),
            'peak_rss_bytes'      => $usage['ru_maxrss'] * 1024,
        ];
    }
}
