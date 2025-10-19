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
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
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
    /**
     * @param string $sourceDirectory
     *
     * @return self
     */
    public function setSourceDirectory(string $sourceDirectory): self;

    /**
     * @param string $targetDirectory
     *
     * @return self
     */
    public function setTargetDirectory(string $targetDirectory): self;

    /**
     * @param bool $useFileExtensionFromSource
     *
     * @return self
     */
    public function setUseFileExtensionFromSource(bool $useFileExtensionFromSource): self;

    /**
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @param RecursiveIteratorIterator            $iterator
     * @param RenameStrategyInterface              $renameStrategy
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy
     *
     * @return FileDuplicateCollection
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
    ): FileDuplicateCollection;

    /**
     * Creates a consecutive new filename for all duplicate files.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     *
     * @return FileDuplicateCollection
     */
    public function createDuplicateFilenames(FileDuplicateCollection $fileDuplicateCollection): FileDuplicateCollection;
}
