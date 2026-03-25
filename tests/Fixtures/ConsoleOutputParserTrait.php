<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use function basename;
use function preg_match_all;
use function preg_replace;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Shared helper for parsing rename pipeline console output into structured
 * tag assignments. Used by integration tests that verify tag propagation
 * ([W], [F], [S], etc.) rather than target filenames.
 */
trait ConsoleOutputParserTrait
{
    /**
     * Parses console output into a map of relative source paths to their assigned
     * output tags ([O], [R], [D], [F], [W], [C], [S], [E]).
     *
     * @return array<string, string> source filename => tag letter (O, R, D, F, W, C, S, E)
     */
    private function extractTagAssignments(string $consoleOutput, string $workspace): array
    {
        $clean = preg_replace('/<[^>]+>/', '', $consoleOutput) ?? $consoleOutput;

        $assignments    = [];
        $absolutePrefix = rtrim($workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relativePrefix = basename(rtrim($workspace, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;

        if (preg_match_all('/\[([ORDFWCSE])]\s+(\S+)/', $clean, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $source = $this->stripOutputPrefix($match[2], $absolutePrefix, $relativePrefix);

                $assignments[$source] = $match[1];
            }
        }

        return $assignments;
    }

    /**
     * Strips the absolute or relative workspace prefix from a path.
     */
    private function stripOutputPrefix(string $path, string $absolutePrefix, string $relativePrefix): string
    {
        if (str_starts_with($path, $absolutePrefix)) {
            return substr($path, strlen($absolutePrefix));
        }

        if (str_starts_with($path, $relativePrefix)) {
            return substr($path, strlen($relativePrefix));
        }

        return $path;
    }
}
