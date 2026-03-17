<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use MagicSunday\Renamer\Exception\TargetFilenameException;
use SplFileInfo;

/**
 * Contract for strategies that compute a target filename from a source file.
 * Each rename command selects the appropriate strategy (EXIF date, pattern match,
 * lowercase, etc.) and passes it to the duplicate detection pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface RenameStrategyInterface
{
    /**
     * Computes the target filename (including extension) for the given source file.
     * Returns null when the strategy cannot produce a valid name (e.g. missing EXIF data).
     *
     * @param SplFileInfo $splFileInfo Source file to generate a target filename for
     *
     * @return string|null Target filename, or null when not applicable
     *
     * @throws TargetFilenameException When the strategy encounters an error generating the filename
     */
    public function generateFilename(SplFileInfo $splFileInfo): ?string;
}
