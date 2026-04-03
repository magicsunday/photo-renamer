<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\LegacyRenameExecutor;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use Override;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Handles command-facing file system interactions while delegating narrower
 * responsibilities to specialized collaborators.
 *
 * The facade keeps the legacy command wiring stable, while:
 * - {@see FileCollector} owns directory traversal
 * - {@see RuntimeCollisionPathAllocator} owns runtime duplicate-suffix fallback
 * - {@see RuntimeFileMoveExecutor} owns concrete runtime move/fallback mechanics
 * - {@see ExecutionPlanExecutor} owns the ExecutionPlan runtime execution path
 * - {@see RenameOutputRenderer} owns output projection and presentation
 *
 * The remaining responsibility of this class is orchestrating physical file
 * mutation and occupied-path tracking around those collaborators.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FileSystemService implements FileSystemServiceInterface
{
    private RuntimeFileMoveExecutor $runtimeFileMoveExecutor;

    private ExecutionPlanExecutor $executionPlanExecutor;

    private LegacyRenameExecutor $legacyRenameExecutor;

    /**
     * @param RenameOutputRenderer          $renderer                      Handles output entry building and summary rendering
     * @param ProgressReporterInterface     $progressReporter              Narrow reporting boundary used by deeper filesystem collaborators
     * @param Filesystem                    $filesystem                    Symfony Filesystem for file operations
     * @param FileCollector                 $fileCollector                 Collects files and creates iterators for directory scans
     * @param RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator Allocates duplicate-suffix fallback paths during runtime collisions
     * @param RuntimeFileMoveExecutor|null  $runtimeFileMoveExecutor       Performs concrete runtime moves with duplicate-suffix fallback handling
     * @param ExecutionPlanExecutor|null    $executionPlanExecutor         Executes the runtime ExecutionPlan path behind the stable facade
     * @param LegacyRenameExecutor|null     $legacyRenameExecutor          Executes the bounded legacy rename flow behind the stable facade
     */
    public function __construct(
        private RenameOutputRenderer $renderer,
        private ProgressReporterInterface $progressReporter,
        private Filesystem $filesystem = new Filesystem(),
        private FileCollector $fileCollector = new FileCollector(),
        private RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator = new RuntimeCollisionPathAllocator(),
        ?RuntimeFileMoveExecutor $runtimeFileMoveExecutor = null,
        ?ExecutionPlanExecutor $executionPlanExecutor = null,
        ?LegacyRenameExecutor $legacyRenameExecutor = null,
    ) {
        $this->runtimeFileMoveExecutor = $runtimeFileMoveExecutor
            ?? new RuntimeFileMoveExecutor($this->progressReporter, $this->filesystem, $this->runtimeCollisionPathAllocator);
        $this->executionPlanExecutor = $executionPlanExecutor
            ?? new ExecutionPlanExecutor($this->progressReporter, $this->runtimeFileMoveExecutor);
        $this->legacyRenameExecutor = $legacyRenameExecutor
            ?? new LegacyRenameExecutor($this->progressReporter, $this->renderer, $this->runtimeFileMoveExecutor);
    }

    /**
     * Creates an iterator for traversing files in the given directory.
     *
     * @param string                                      $directory         The directory that should be scanned
     * @param RecursiveIterator<string, SplFileInfo>|null $recursiveIterator Optional preconfigured iterator to use instead of instantiating a default one
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> Iterator yielding only leaf nodes (files)
     */
    #[Override]
    public function createFileIterator(
        string $directory,
        ?RecursiveIterator $recursiveIterator = null,
    ): RecursiveIteratorIterator {
        return $this->fileCollector->createFileIterator($directory, $recursiveIterator);
    }

    /**
     * Collects all regular files from the given directory into a flat list.
     *
     * @param string $directory Absolute directory path to scan
     *
     * @return list<SplFileInfo> All files found in the directory tree
     */
    #[Override]
    public function collectFiles(string $directory): array
    {
        return $this->fileCollector->collectFiles($directory);
    }

    /**
     * Renames or copies files represented by the provided duplicate collection.
     *
     * This is the main entry point for the "legacy" rename flow (not using
     * the ExecutionPlan). It handles building output entries, rendering
     * progress, and performing the actual move operations.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection The collection of planned renames.
     * @param RenameOptions           $options                 Configuration for the rename run.
     * @param RenameResult            $result                  Aggregate results from the pipeline.
     * @param list<string>|null       $showFilter              Optional tag filter for console output.
     */
    #[Override]
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
        RenameResult $result = new RenameResult(),
        ?array $showFilter = null,
    ): void {
        $this->legacyRenameExecutor->renameFiles(
            $fileDuplicateCollection,
            $options,
            $result,
            $showFilter,
        );
    }

    /**
     * Executes a runtime plan, performing the actual file rename operations.
     *
     * Builds an occupied-path index from the plan and uses runtime collision
     * fallback as a safety layer to ensure no files are overwritten even if
     * the plan had a logic error.
     *
     * @param ExecutionPlan $plan   The runtime execution plan to process.
     * @param bool          $dryRun When true, simulate without touching the filesystem.
     *
     * @return ExecutionResult Runtime execution counters (moves, fallbacks, errors).
     */
    #[Override]
    public function executePlan(ExecutionPlan $plan, bool $dryRun = false): ExecutionResult
    {
        return $this->executionPlanExecutor->executePlan($plan, $dryRun);
    }
}
