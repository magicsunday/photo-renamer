<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

/**
 * Represents one path split into its directory prefix and trailing filename.
 *
 * The diff highlighter compares directory and basename parts differently. This
 * value object replaces the former private tuple contract so that even the
 * local path-splitting step stays explicit and named inside the output module.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class PathPrefixSplit
{
    /**
     * @param string $directoryPrefix Directory portion including trailing slash when present
     * @param string $filename        Final filename segment without the directory prefix
     */
    public function __construct(
        public string $directoryPrefix,
        public string $filename,
    ) {
    }
}
