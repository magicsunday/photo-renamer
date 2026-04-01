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
 * Immutable value object carrying all user-supplied configuration options
 * for a single in-place rename execution. Passed through the entire pipeline
 * from command input parsing down to the file system service.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameOptions
{
    /**
     * @param bool        $dryRun              When true, renames are simulated without touching the file system
     * @param bool        $listAll             When true, all files are listed in output including unchanged ones
     * @param string|null $sourceBaseDirectory Absolute path to the directory scanned for source files
     * @param int|null    $maxDateDrift        Maximum allowed date drift in days (0 = disabled, null = disabled)
     */
    public function __construct(
        public bool $dryRun = false,
        public bool $listAll = false,
        public ?string $sourceBaseDirectory = null,
        public ?int $maxDateDrift = null,
    ) {
    }
}
