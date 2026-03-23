<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifier;

use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use Override;
use SplFileInfo;

/**
 * Groups files by their binary content using xxh128 hashing. Two files with
 * identical content produce the same identifier regardless of filename, enabling
 * true duplicate detection within a target-basename group (hash sub-grouping).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ContentHashStrategy implements DuplicateIdentifierStrategyInterface
{
    public function __construct(
        private SafeHashCalculatorInterface $hashCalculator,
    ) {
    }

    /**
     * Computes an xxh128 hash of the source file's content. The target file info
     * is unused because grouping is based on the actual binary data on disk.
     *
     * @param SplFileInfo $sourceFileInfo Source file whose content is hashed
     * @param SplFileInfo $targetFileInfo Unused by this strategy
     *
     * @return string Hex-encoded xxh128 hash
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string
    {
        return $this->hashCalculator->hashFile($sourceFileInfo, 'xxh128');
    }
}
