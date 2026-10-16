<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Exception\AnalysisMemoryBudgetExceededException;
use MagicSunday\Renamer\Helper\AnalysisMemoryBudget;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function str_replace;
use function substr_count;
use function usort;

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
     *
     * @throws AnalysisMemoryBudgetExceededException When file retention reaches the pre-mutation budget
     */
    public static function collectAndSortFiles(RecursiveIteratorIterator $iterator): array
    {
        $files = [];

        foreach (self::collectAndSortPaths($iterator) as $pathname) {
            AnalysisMemoryBudget::assertHeadroom('file collection');
            $files[] = new SplFileInfo($pathname);
        }

        return $files;
    }

    /**
     * Keeps path values while sorting, without retaining native filesystem buffers.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator Candidate files
     *
     * @return list<string> Parent-first paths, then bytewise pathname order
     *
     * @throws AnalysisMemoryBudgetExceededException When path retention reaches the pre-mutation budget
     */
    public static function collectAndSortPaths(RecursiveIteratorIterator $iterator): array
    {
        /** @var list<string> $files */
        $files = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            AnalysisMemoryBudget::assertHeadroom('file collection');
            $files[] = $fileInfo->getPathname();
        }

        AnalysisMemoryBudget::assertHeadroom('file sorting');

        usort($files, static function (string $fileA, string $fileB): int {
            $depthA = self::pathDepth($fileA);
            $depthB = self::pathDepth($fileB);

            return ($depthA !== $depthB)
                ? $depthA <=> $depthB
                : $fileA <=> $fileB;
        });

        return $files;
    }

    /**
     * Counts path separators in a platform-neutral pathname string.
     */
    private static function pathDepth(string $file): int
    {
        return substr_count(str_replace('\\', '/', $file), '/');
    }
}
