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
 * Interface for rename strategies that can resolve Live Photo content identifiers.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface LivePhotoAwareRenameStrategyInterface extends RenameStrategyInterface
{
    /**
     * Returns the Live Photo content identifier for the given file, if available.
     *
     * @param SplFileInfo $splFileInfo The file info instance
     */
    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string;
}
