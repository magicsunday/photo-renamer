<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\DuplicateIdentifierProcessor;

use SplFileInfo;

/**
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface DuplicateIdentifierProcessorInterface
{
    /**
     * Returns a unique identifier for a file. Returns FALSE if no valid identifier could be generated.
     *
     * @param SplFileInfo $sourceFileInfo The source file info
     * @param SplFileInfo $targetFileInfo The target file info
     *
     * @return string|false
     */
    public function __invoke(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false;
}
