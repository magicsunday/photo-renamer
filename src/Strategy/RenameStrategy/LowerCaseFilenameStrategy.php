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
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::generateFilename($splFileInfo);

        return mb_strtolower($targetFilename);
    }
}
