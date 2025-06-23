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
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class ContentHashIdentifierProcessor implements DuplicateIdentifierProcessorInterface
{
    #[Override]
    public function __invoke(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        // We want to find duplicates across all directories based on a hash of the file contents.
        return hash_file('xxh128', $sourceFileInfo->getPathname());
    }
}
