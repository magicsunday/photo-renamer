<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function substr_count;
use function usort;

use const DIRECTORY_SEPARATOR;

/**
 * Collects and sorts files yielded by recursive iterators.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class SortedFileIteratorCollector
{
    /**
     * Collects all files from the iterator and sorts parent directories before subdirectories.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator Iterator yielding candidate files
     *
     * @return list<SplFileInfo> Sorted file list
     */
    public static function collectAndSortFiles(RecursiveIteratorIterator $iterator): array
    {
        /** @var list<SplFileInfo> $files */
        $files = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $files[] = $fileInfo;
        }

        usort($files, static function (SplFileInfo $fileA, SplFileInfo $fileB): int {
            $depthA = substr_count($fileA->getPath(), DIRECTORY_SEPARATOR);
            $depthB = substr_count($fileB->getPath(), DIRECTORY_SEPARATOR);

            return ($depthA !== $depthB)
                ? $depthA <=> $depthB
                : $fileA->getPathname() <=> $fileB->getPathname();
        });

        return $files;
    }
}
