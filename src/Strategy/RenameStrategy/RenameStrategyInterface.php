<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use SplFileInfo;

/**
 * Interface for renaming strategies.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface RenameStrategyInterface
{
    /**
     * Generates a unique identifier for a file to detect duplicates.
     *
     * @param SplFileInfo $splFileInfo The file info instance
     *
     * @return string|null
     */
    public function generateFilename(SplFileInfo $splFileInfo): ?string;
}
