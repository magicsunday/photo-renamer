<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use RuntimeException;

use function dirname;
use function pathinfo;
use function sprintf;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_BASENAME;
use const PATHINFO_EXTENSION;

/**
 * Allocates safe fallback paths when a runtime rename target is already occupied.
 *
 * FileSystemService uses this allocator as a last-resort execution safety net:
 * if a target path becomes occupied during the batch, the allocator appends the
 * next available duplicate suffix without nesting already-existing suffixes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RuntimeCollisionPathAllocator
{
    /**
     * Finds the next available duplicate target path that is not occupied.
     *
     * Strips any existing duplicate suffix from the target basename to avoid
     * nested suffixes, then increments a counter until an unoccupied candidate
     * path is found.
     *
     * @param string              $targetPath    Absolute target file path
     * @param array<string, true> $occupiedPaths Current set of occupied paths
     *
     * @return string An available absolute path with a duplicate suffix appended
     *
     * @throws RuntimeException When the maximum duplicate suffix count is exceeded
     */
    public function findAvailableDuplicatePath(string $targetPath, array $occupiedPaths): string
    {
        $ext      = pathinfo($targetPath, PATHINFO_EXTENSION);
        $dir      = dirname($targetPath);
        $basename = pathinfo($targetPath, PATHINFO_BASENAME);

        if ($ext !== '') {
            $basename = substr($basename, 0, -(strlen($ext) + 1));
        }

        $basename = FileHelper::stripDuplicateSuffix($basename);

        $counter = 1;

        do {
            if ($counter > Constants::MAX_DUPLICATE_SUFFIX) {
                throw new RuntimeException(
                    sprintf('Exceeded %d attempts finding available target for "%s"', Constants::MAX_DUPLICATE_SUFFIX, $basename)
                );
            }

            $candidatePath = sprintf(
                '%s%s%s%s%03d.%s',
                $dir,
                DIRECTORY_SEPARATOR,
                $basename,
                Constants::DUPLICATE_IDENTIFIER,
                $counter,
                $ext,
            );

            ++$counter;
        } while (isset($occupiedPaths[$candidatePath]));

        return $candidatePath;
    }
}
