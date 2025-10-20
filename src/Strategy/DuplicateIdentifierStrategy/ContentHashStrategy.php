<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy;

use MagicSunday\Renamer\Service\SafeHashCalculator;
use Override;
use SplFileInfo;

/**
 * Strategy that identifies duplicates based on file content hash.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class ContentHashStrategy implements DuplicateIdentifierStrategyInterface
{
    public function __construct(
        private readonly SafeHashCalculator $hashCalculator,
    ) {
    }

    /**
     * Generates a unique identifier for a file based on its content hash.
     *
     * @param SplFileInfo $sourceFileInfo The source file
     * @param SplFileInfo $targetFileInfo The target file
     *
     * @return string|false A unique identifier for the file or false in case of an error
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        return $this->hashCalculator->hashFile($sourceFileInfo, 'xxh128');
    }
}
