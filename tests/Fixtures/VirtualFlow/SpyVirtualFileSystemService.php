<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures\VirtualFlow;

use LogicException;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use Override;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Spy filesystem boundary for virtual command-flow tests.
 *
 * The command-level virtual harness must reach the execution boundary without
 * touching the real filesystem. This spy returns a prebuilt iterator for scan
 * setup and records the plan passed to `executePlan()` so tests can assert that
 * command orchestration reached the correct non-mutating boundary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class SpyVirtualFileSystemService implements FileSystemServiceInterface
{
    private ?ExecutionPlan $capturedExecutionPlan = null;

    private bool $capturedDryRun = false;

    private int $executePlanCalls = 0;

    /**
     * @param RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> $iterator Iterator returned to the command scan phase
     */
    public function __construct(private readonly RecursiveIteratorIterator $iterator)
    {
    }

    /**
     * Returns the preconfigured virtual iterator regardless of the requested directory.
     *
     * @param string                                      $directory         Ignored virtual source directory
     * @param RecursiveIterator<string, SplFileInfo>|null $recursiveIterator Ignored iterator override
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> Preconfigured virtual iterator
     */
    #[Override]
    public function createFileIterator(string $directory, ?RecursiveIterator $recursiveIterator = null): RecursiveIteratorIterator
    {
        return $this->iterator;
    }

    /**
     * Virtual command-flow tests do not use the collection API directly.
     *
     * @param string $directory Ignored virtual source directory
     *
     * @return list<SplFileInfo> Empty list because the harness uses createFileIterator()
     */
    #[Override]
    public function collectFiles(string $directory): array
    {
        return [];
    }

    /**
     * The legacy rename flow is intentionally unsupported in this virtual harness.
     *
     * @throws LogicException Always, because the command-level harness should stay on the ExecutionPlan path
     */
    #[Override]
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
        RenameResult $result = new RenameResult(),
        ?array $showFilter = null,
    ): void {
        throw new LogicException('SpyVirtualFileSystemService only supports executePlan() in command-flow tests.');
    }

    /**
     * Records the execution request without touching the real filesystem.
     *
     * @param ExecutionPlan $plan   Execution plan passed by the command
     * @param bool          $dryRun Whether the command requested dry-run mode
     *
     * @return ExecutionResult Empty runtime result because no file operations run
     */
    #[Override]
    public function executePlan(ExecutionPlan $plan, bool $dryRun = false): ExecutionResult
    {
        $this->capturedExecutionPlan = $plan;
        $this->capturedDryRun        = $dryRun;
        ++$this->executePlanCalls;

        return new ExecutionResult();
    }

    /**
     * Returns the last captured execution plan, if any.
     */
    public function getCapturedExecutionPlan(): ?ExecutionPlan
    {
        return $this->capturedExecutionPlan;
    }

    /**
     * Returns whether the captured execution request used dry-run mode.
     */
    public function getCapturedDryRun(): bool
    {
        return $this->capturedDryRun;
    }

    /**
     * Returns how often executePlan() was called.
     */
    public function getExecutePlanCalls(): int
    {
        return $this->executePlanCalls;
    }
}
