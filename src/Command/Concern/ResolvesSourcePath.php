<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command\Concern;

use Symfony\Component\Console\Input\InputInterface;

use function is_string;
use function realpath;

/**
 * Small command concern that resolves the `source` argument to an absolute path.
 *
 * `rename:verify` and `rename:write-date` both accept either a single file or a
 * directory and need the same canonicalization and existence check before any
 * further processing starts.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
trait ResolvesSourcePath
{
    /**
     * Resolves the source path argument to an absolute existing path.
     *
     * @param InputInterface $input The input interface carrying the `source` argument.
     *
     * @return string|null The resolved absolute path, or null if it does not exist.
     */
    private function resolveSourcePath(InputInterface $input): ?string
    {
        $source = $input->getArgument('source');

        if (!is_string($source)) {
            return null;
        }

        $resolved = realpath($source);

        if ($resolved === false) {
            return null;
        }

        return $resolved;
    }
}
