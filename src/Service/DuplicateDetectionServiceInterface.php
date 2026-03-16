<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveIterator;
use RecursiveIteratorIterator;

/**
 * Interface for duplicate detection operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface DuplicateDetectionServiceInterface
{
    public function setSourceDirectory(string $sourceDirectory): self;

    public function setTargetDirectory(string $targetDirectory): self;

    public function setUseFileExtensionFromSource(bool $useFileExtensionFromSource): self;

    /**
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
    ): FileDuplicateCollection;

    /**
     * Creates a consecutive new filename for all duplicate files.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection collection whose entries should receive duplicate filenames
     * @param bool                    $skipHashSubGrouping     when true, content-hash sub-grouping is skipped entirely
     */
    public function createDuplicateFilenames(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $skipHashSubGrouping = false,
    ): FileDuplicateCollection;

    /**
     * Returns the number of groups where content-hash sub-grouping was applied.
     */
    public function getNamingCollisions(): int;
}
