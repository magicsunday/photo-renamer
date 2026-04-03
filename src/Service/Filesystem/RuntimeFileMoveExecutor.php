<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

use function basename;
use function dirname;
use function sprintf;

/**
 * Performs concrete file moves while enforcing runtime duplicate-suffix
 * fallbacks against the mutable occupied-path index.
 *
 * Both the legacy rename path and the `ExecutionPlan` runtime path need the
 * same last-resort safety behavior: if a target becomes occupied during the
 * batch, the file must be redirected to the next available duplicate-suffixed
 * path instead of overwriting an earlier item. This collaborator centralizes
 * that boundary logic so it stays identical across both execution paths.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RuntimeFileMoveExecutor
{
    /**
     * @param ProgressReporterInterface     $progressReporter              Reporter used for runtime fallback diagnostics.
     * @param Filesystem                    $filesystem                    Symfony Filesystem used for mkdir/rename operations.
     * @param RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator Allocates duplicate-suffix fallbacks when a target becomes occupied during execution.
     */
    public function __construct(
        private ProgressReporterInterface $progressReporter,
        private Filesystem $filesystem,
        private RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator,
    ) {
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
    public function moveFileByPath(
        string $sourcePath,
        string $targetPath,
        array &$occupiedPaths,
        bool $dryRun,
    ): string {
        $plannedTarget = $targetPath;

        if (($targetPath !== $sourcePath) && isset($occupiedPaths[$targetPath])) {
            $targetPath = $this->runtimeCollisionPathAllocator->findAvailableDuplicatePath($targetPath, $occupiedPaths);
        }

        if ($targetPath !== $plannedTarget) {
            $this->progressReporter->text(sprintf(
                '<fg=yellow>Runtime collision fallback:</> %s → %s (planned: %s)',
                basename($sourcePath),
                basename($targetPath),
                basename($plannedTarget),
            ));
        }

        if (!$dryRun) {
            $sourceFileInfo = new SplFileInfo($sourcePath);

            if (!$sourceFileInfo->isFile()) {
                throw new RuntimeException(
                    sprintf('Source file "%s" does not exist', $sourcePath),
                );
            }

            $this->filesystem->mkdir(dirname($targetPath));
            $this->filesystem->rename($sourcePath, $targetPath);
        }

        unset($occupiedPaths[$sourcePath]);
        $occupiedPaths[$targetPath] = true;

        return $targetPath;
    }
}
