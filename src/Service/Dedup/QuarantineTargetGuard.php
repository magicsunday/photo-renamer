<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dedup;

use RuntimeException;
use Symfony\Component\Filesystem\Path;

use function clearstatcache;
use function count;
use function explode;
use function file_exists;
use function in_array;
use function is_dir;
use function is_link;
use function preg_match;
use function realpath;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * Keeps quarantine destinations below the selected canonical source directory.
 * Existing links are rejected even when they currently resolve inside that root:
 * their meaning can change independently of the intended quarantine layout.
 * Checks detect observed unsafe paths; they are not descriptor-based operations
 * and require other writers to leave the directory tree unchanged during cleanup.
 */
final readonly class QuarantineTargetGuard
{
    /**
     * Validates a relative quarantine directory before scanning or confirmation.
     * Parent traversal is rejected rather than normalized away, making the
     * operator's selected boundary explicit on every supported platform.
     *
     * @param string $sourceRoot Canonical absolute source directory
     * @param string $target     Relative quarantine directory selected by the operator
     *
     * @return string Canonical absolute quarantine directory
     */
    public function resolveDirectory(string $sourceRoot, string $target): string
    {
        $relative = str_replace('\\', '/', $target);

        if (Path::isAbsolute($relative) || (preg_match('/^[A-Za-z]:/', $relative) === 1) || in_array('..', explode('/', $relative), true) || str_contains($relative, "\0")) {
            throw new RuntimeException('Quarantine target must be a relative subdirectory without parent traversal.');
        }

        $relative = Path::canonicalize($relative);

        if (($relative === '') || ($relative === '.') || Path::isAbsolute($relative)) {
            throw new RuntimeException('Quarantine target must select a subdirectory, not the source root itself.');
        }

        $directory = Path::canonicalize($sourceRoot . '/' . $relative);
        $this->assertComponents($sourceRoot, $directory, true);

        return $directory;
    }

    /**
     * Rechecks all destination components, including a dangling final symlink.
     * Callers run this for every planned move before any mutation, and again
     * immediately around directory creation and before the actual rename.
     *
     * @param string $sourceRoot Canonical absolute source directory
     * @param string $targetPath Absolute destination filename
     *
     * @return void
     */
    public function assertSafeTarget(string $sourceRoot, string $targetPath): void
    {
        $this->assertComponents($sourceRoot, $targetPath, false);
    }

    /**
     * Rejects lexical escapes, changed roots and link/non-directory components.
     * Fresh stat checks avoid retaining a pre-confirmation view of the tree.
     *
     * @param string $sourceRoot          Canonical absolute source directory
     * @param string $path                Absolute path whose components must be checked
     * @param bool   $terminalIsDirectory Whether the last component must also be a directory
     *
     * @return void
     */
    private function assertComponents(string $sourceRoot, string $path, bool $terminalIsDirectory): void
    {
        $sourceRoot = Path::canonicalize($sourceRoot);
        $path       = Path::canonicalize($path);
        clearstatcache(true);

        if (!Path::isBasePath($sourceRoot, $path) || (realpath($sourceRoot) !== $sourceRoot) || !is_dir($sourceRoot)) {
            throw new RuntimeException('Quarantine target is outside the unchanged canonical source directory.');
        }

        $components = explode('/', Path::makeRelative($path, $sourceRoot));
        $current    = $sourceRoot;
        $last       = count($components) - 1;

        foreach ($components as $index => $component) {
            $current .= '/' . $component;
            clearstatcache(true, $current);

            if (is_link($current)) {
                throw new RuntimeException(sprintf('Quarantine target contains a symbolic link: "%s".', $current));
            }

            if (!file_exists($current)) {
                continue;
            }

            if (realpath($current) !== $current) {
                throw new RuntimeException(sprintf('Quarantine target no longer resolves to its intended path: "%s".', $current));
            }

            if (($terminalIsDirectory || ($index !== $last)) && !is_dir($current)) {
                throw new RuntimeException(sprintf('Quarantine parent is not a directory: "%s".', $current));
            }

            if (!$terminalIsDirectory && ($index === $last) && is_dir($current)) {
                throw new RuntimeException(sprintf('Quarantine file target is a directory: "%s".', $current));
            }
        }
    }
}
