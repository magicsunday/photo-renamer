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
 * Extended rename strategy that additionally exposes Apple Live Photo content
 * identifiers. Enables the duplicate detection pipeline to build a content
 * identifier map for companion pairing (HEIC/JPG + MOV) without resorting
 * to method_exists() checks.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface LivePhotoAwareRenameStrategyInterface extends RenameStrategyInterface
{
    /**
     * Returns the canonical Live Photo content identifier for the given file,
     * or null when the file is not part of an Apple Live Photo pair.
     *
     * @param SplFileInfo $splFileInfo Source file to query
     *
     * @return string|null Lowercased content identifier, or null
     *
     * @throws TargetFilenameException When reading metadata fails
     */
    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string;
}
