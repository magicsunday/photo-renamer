<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use FilesystemIterator;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Regex\SafeRegex;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Collects files from a directory tree for commands and source identity checks.
 *
 * Iterator creation and flat collection share filtering semantics without
 * depending on rename execution, output presentation or mutation state.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FileCollector
{
    /**
     * @param SafeRegex $safeRegex Safe regex wrapper propagated into recursive file filter iterators
     */
    public function __construct(
        private SafeRegex $safeRegex,
    ) {
    }

    /**
     * Creates an iterator for traversing files in the given directory.
     *
     * @param string                                      $directory         The directory that should be scanned
     * @param RecursiveIterator<string, SplFileInfo>|null $recursiveIterator Optional preconfigured iterator to use instead of instantiating a default one
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> Iterator yielding only leaf nodes (files)
     */
    public function createFileIterator(
        string $directory,
        ?RecursiveIterator $recursiveIterator = null,
    ): RecursiveIteratorIterator {
        if (!$recursiveIterator instanceof RecursiveIterator) {
            $recursiveIterator = new RecursiveRegexFileFilterIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                ),
                // Linux filenames may contain line breaks; the default scan
                // must include them so display safety cannot be bypassed by
                // silently hiding files from the collected set.
                '/\A.+\z/s',
                $this->safeRegex,
            );
        }

        return new RecursiveIteratorIterator(
            $recursiveIterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    /**
     * Collects all regular files from the given directory into a flat list.
     *
     * @param string $directory Absolute directory path to scan
     *
     * @return list<SplFileInfo> All files found in the directory tree
     */
    public function collectFiles(string $directory): array
    {
        $iterator = $this->createFileIterator($directory);

        /** @var list<SplFileInfo> $files */
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
