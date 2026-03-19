<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function assert;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Provides temporary workspace creation and recursive cleanup for test classes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
trait WorkspaceTrait
{
    /**
     * Creates a temporary directory for use during a test.
     *
     * @param string $prefix Directory name prefix
     *
     * @return string Absolute path to the created directory
     */
    private function createTempWorkspace(string $prefix = 'renamer_'): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid($prefix, true);

        if (!mkdir($directory, 0o755) && !is_dir($directory)) {
            self::fail('Unable to create temporary workspace: ' . $directory);
        }

        return $directory;
    }

    /**
     * Recursively removes a directory and all its contents.
     *
     * @param string $directory Absolute path to the directory to remove
     */
    private function removeWorkspace(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            assert($fileInfo instanceof SplFileInfo);

            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());

                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}
