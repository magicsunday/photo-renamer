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
use function clearstatcache;
use function dirname;
use function is_link;
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
     * @param SourceIdentityGuard           $sourceIdentityGuard           Compares current bytes/inode against the CLI's pre-analysis source state
     */
    public function __construct(
        private ProgressReporterInterface $progressReporter,
        private Filesystem $filesystem,
        private RuntimeCollisionPathAllocator $runtimeCollisionPathAllocator,
        private SourceIdentityGuard $sourceIdentityGuard,
    ) {
    }

    /**
     * Moves a single file from source path to target path while respecting the
     * mutable occupied-path index.
     *
     * If the requested target has become occupied by an earlier item in the same
     * run, the executor falls back to the next free duplicate-suffixed path to
     * prevent overwriting files. The occupied-path index is updated even in dry
     * runs so later simulated items see the same path transitions. An external
     * target observed on disk after planning blocks the move and retains the
     * source, including unreadable files and dangling symbolic links.
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

        if (!$dryRun && ($sourcePath !== $targetPath)) {
            $sourceFileInfo = new SplFileInfo($sourcePath);

            if (!$sourceFileInfo->isFile()) {
                throw new RuntimeException(
                    sprintf('Source file "%s" does not exist', $sourcePath),
                );
            }

            $this->filesystem->mkdir(dirname($targetPath));

            // Symfony's overwrite=false check tests readability and does not
            // reject unreadable files or dangling links. Observe all existing
            // leaves immediately before handing off, without claiming atomicity
            // against an external writer racing the subsequent rename syscall.
            clearstatcache(true, $targetPath);

            if ($this->filesystem->exists($targetPath) || is_link($targetPath)) {
                throw new RuntimeException(sprintf('Target "%s" became occupied after planning; source retained.', $targetPath));
            }

            $this->sourceIdentityGuard->assertUnchanged($sourcePath);
            $this->filesystem->rename($sourcePath, $targetPath);
        }

        unset($occupiedPaths[$sourcePath]);
        $occupiedPaths[$targetPath] = true;

        return $targetPath;
    }
}
