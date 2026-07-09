<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

/**
 * Represents the configuration options for a renaming operation.
 *
 * This immutable value object carries all user-supplied settings gathered from
 * the command-line interface. It is passed throughout the entire pipeline
 * to ensure that all processing steps (grouping, naming, execution, and output)
 * respect the same set of configuration rules.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameOptions
{
    /**
     * @param bool        $dryRun              If true, the operation is simulated,
     *                                         and no changes are made to the
     *                                         file system.
     * @param bool        $listAll             If true, all processed files (including
     *                                         those that are not renamed) are
     *                                         listed in the final output.
     * @param string|null $sourceBaseDirectory The absolute path to the base
     *                                         directory being scanned. Used for
     *                                         path relativization in reports.
     * @param int|null    $maxDateDrift        The maximum allowed difference in
     *                                         days between filename dates and
     *                                         metadata dates (null to disable).
     */
    public function __construct(
        public bool $dryRun = false,
        public bool $listAll = false,
        public ?string $sourceBaseDirectory = null,
        public ?int $maxDateDrift = null,
    ) {
    }
}
