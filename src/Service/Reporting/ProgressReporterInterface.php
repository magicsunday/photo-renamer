<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Reporting;

/**
 * Narrow progress and diagnostic reporting boundary for domain services.
 *
 * Domain services still need to communicate phase headings, progress updates,
 * and recoverable diagnostics while processing large collections. This
 * interface keeps those services independent from Symfony Console and lets
 * tests use silent reporters without constructing terminal IO objects.
 *
 * The contract intentionally stays small and single-progress oriented because
 * the current pipeline services never need multiple concurrent progress bars.
 * Reporters without phase-title semantics must implement {@see section()} as
 * a no-op rather than forcing calling code to branch on reporter capabilities.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface ProgressReporterInterface
{
    /**
     * Starts a progress indicator for a workload with the given step count.
     *
     * @param int $max Maximum number of steps the progress indicator represents
     */
    public function startProgress(int $max): void;

    /**
     * Advances the active progress indicator by the given number of steps.
     *
     * @param int $step Number of processed steps to advance
     */
    public function advance(int $step = 1): void;

    /**
     * Finishes the active progress indicator and finalizes its output.
     */
    public function finish(): void;

    /**
     * Emits a plain informational message without changing domain behavior.
     *
     * @param string $message Operator-facing informational text
     */
    public function text(string $message): void;

    /**
     * Emits a non-fatal diagnostic error message.
     *
     * @param string $message Operator-facing error text
     */
    public function error(string $message): void;

    /**
     * Emits a debug-only diagnostic message when the active reporter supports it.
     *
     * Reporters without debug output should silently ignore the message so
     * callers never need to branch on reporter capabilities.
     *
     * @param string $message Debug-only diagnostic text
     */
    public function debug(string $message): void;

    /**
     * Emits a named phase heading.
     *
     * Reporters without explicit section semantics should implement this as a
     * no-op or the simplest equivalent heading output.
     *
     * @param string $title Operator-facing phase title
     */
    public function section(string $title): void;
}
