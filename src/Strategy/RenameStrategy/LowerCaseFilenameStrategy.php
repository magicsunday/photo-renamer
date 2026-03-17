<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use Override;
use SplFileInfo;

use function mb_strtolower;

/**
 * Converts the inherited filename to lowercase using multibyte-safe conversion.
 * Applied by the rename:lowercase command to normalize mixed-case filenames
 * produced by cameras or operating systems.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
readonly class LowerCaseFilenameStrategy extends InheritFilenameStrategy
{
    /**
     * Returns the inherited filename converted to lowercase, including the extension.
     *
     * @param SplFileInfo $splFileInfo Source file to derive the target name from
     *
     * @return string Fully lowercased filename
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::generateFilename($splFileInfo);

        return mb_strtolower($targetFilename);
    }
}
