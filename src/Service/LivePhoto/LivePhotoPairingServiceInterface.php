<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Discovers companion files (typically MOV videos) that belong to existing Live Photo
 * groups but were not captured during the initial grouping pass.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface LivePhotoPairingServiceInterface
{
    /**
     * Finds additional files that belong to existing Live Photo groups.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator                  iterator traversing all source files
     * @param FileDuplicateCollection           $fileDuplicateCollection   collection generated during the first pass
     * @param callable(SplFileInfo): ?string    $contentIdentifierResolver callback resolving the Live Photo content identifier
     * @param callable(): void|null             $onFileInspected           optional callback invoked after each inspected file
     */
    public function pairByContentIdentifier(
        RecursiveIteratorIterator $iterator,
        FileDuplicateCollection $fileDuplicateCollection,
        callable $contentIdentifierResolver,
        ?callable $onFileInspected = null,
        bool $matchByContentIdentifier = true,
    ): LivePhotoPairingCollection;
}
