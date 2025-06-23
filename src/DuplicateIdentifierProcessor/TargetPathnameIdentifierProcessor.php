<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\DuplicateIdentifierProcessor;

use Override;
use SplFileInfo;

/**
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class TargetPathnameIdentifierProcessor implements DuplicateIdentifierProcessorInterface
{
    #[Override]
    public function __invoke(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string
    {
        // We want to find duplicates in the current directory,
        // so the unique identifier must also contain the path.
        return $targetFileInfo->getPathname();
    }
}
