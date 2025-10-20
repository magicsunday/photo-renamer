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

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class LowerCaseFilenameStrategy extends InheritFilenameStrategy
{
    #[Override]
    /**
     * Generates a lowercase version of the filename produced by the parent strategy.
     *
     * @param SplFileInfo $splFileInfo File information describing the source asset
     *
     * @return string Lowercase filename preserving the original extension casing rules from the base strategy
     */
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::generateFilename($splFileInfo);

        return mb_strtolower($targetFilename);
    }
}
