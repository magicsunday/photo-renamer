<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Execution;

use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Pure DTO representing a single file operation in the execution plan.
 * Contains only string-based paths (no SplFileInfo) — suitable for
 * serialization, dry-run rendering, and runtime output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExecutionItem
{
    /**
     * @param string            $sourcePath           Absolute source file path
     * @param string            $targetPath           Absolute target file path
     * @param ExecutionItemType $type                 Role within the execution group
     * @param bool              $renameRequired       True when source !== target
     * @param bool              $isNoOp               True when file is already at target (source === target)
     * @param string            $groupKey             The capture group key
     * @param bool              $shouldExecute        Whether this item should be executed (false for [W]/[C] skips)
     * @param string|null       $clusterId            Subgroup cluster ID (null if not classified)
     * @param bool              $isDuplicateTarget    Target contains -duplicate-
     * @param bool              $isLivePhotoConflict  Live Photo content-identifier conflict
     * @param bool              $isFallbackDate       Date sourced from fallback tag
     * @param bool              $isAmbiguousTimezone  Timezone could not be determined unambiguously
     * @param string|null       $warningReason        Human-readable warning (if any)
     * @param bool              $isExecutable         Whether this item is eligible for execution
     * @param string|null       $executionBlockReason Human-readable reason why execution is blocked (null when executable or no-op)
     */
    public function __construct(
        public string $sourcePath,
        public string $targetPath,
        public ExecutionItemType $type,
        public bool $renameRequired,
        public bool $isNoOp,
        public string $groupKey,
        public bool $shouldExecute = true,
        public ?string $clusterId = null,
        public bool $isDuplicateTarget = false,
        public bool $isLivePhotoConflict = false,
        public bool $isFallbackDate = false,
        public bool $isAmbiguousTimezone = false,
        public ?string $warningReason = null,
        public bool $isExecutable = true,
        public ?string $executionBlockReason = null,
    ) {
    }

    /**
     * Returns the source path relative to the given base directory.
     *
     * @param string $baseDirectory Absolute base directory path (without trailing slash)
     */
    public function relativeSourcePath(string $baseDirectory): string
    {
        return $this->stripBaseDirectory($this->sourcePath, $baseDirectory);
    }

    /**
     * Returns the target path relative to the given base directory.
     *
     * @param string $baseDirectory Absolute base directory path (without trailing slash)
     */
    public function relativeTargetPath(string $baseDirectory): string
    {
        return $this->stripBaseDirectory($this->targetPath, $baseDirectory);
    }

    /**
     * Strips the base directory prefix (including trailing slash) from the given path.
     *
     * @param string $path          Absolute file path
     * @param string $baseDirectory Absolute base directory path (without trailing slash)
     */
    private function stripBaseDirectory(string $path, string $baseDirectory): string
    {
        $prefix = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }
}
