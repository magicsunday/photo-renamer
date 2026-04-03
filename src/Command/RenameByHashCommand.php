<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\ContentHashStrategy;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;

/**
 * Groups files by their binary content hash (xxh128) and assigns "-duplicate-NNN"
 * suffixes to all but the canonical file in each group. Files retain their original
 * filename (minus any existing duplicate suffix). Skips content-hash sub-grouping
 * since grouping is already hash-based.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RenameByHashCommand extends AbstractRenameCommand
{
    private ?RenameStrategyInterface $renameStrategy = null;

    private ?DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy = null;

    /**
     * Constructor.
     *
     * @param FileSystemServiceInterface         $fileSystemService         Service to handle file system operations
     * @param DuplicateDetectionServiceInterface $duplicateDetectionService Service to handle grouping and duplicate resolution
     * @param SafeRegex                          $safeRegex                 Safe regex wrapper used by the shared legacy file iterator path
     * @param SafeHashCalculatorInterface        $hashCalculator            Service to calculate secure file hashes
     */
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        SafeRegex $safeRegex,
        private readonly SafeHashCalculatorInterface $hashCalculator,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService, $safeRegex);
    }

    /**
     * Configures the current command.
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:hash')
            ->setDescription(
                'Groups identical files by content hash and renames duplicates.'
            );
    }

    /**
     * Returns the rename strategy.
     *
     * Uses the InheritFilenameStrategy, which keeps the original filename
     * but removes any existing duplicate suffixes.
     *
     * @return RenameStrategyInterface The rename strategy
     */
    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        return $this->renameStrategy ??= new InheritFilenameStrategy();
    }

    /**
     * Returns the duplicate identifier strategy.
     *
     * Uses the ContentHashStrategy to group files by their binary content.
     *
     * @return DuplicateIdentifierStrategyInterface The duplicate identifier strategy
     */
    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return $this->duplicateIdentifierStrategy ??= new ContentHashStrategy($this->hashCalculator);
    }

    /**
     * Skips content-hash sub-grouping.
     *
     * Since the main grouping is already based on content hashes, there is
     * no need for a second pass of sub-grouping by hash.
     *
     * @return bool Always true
     */
    #[Override]
    protected function skipHashSubGrouping(): bool
    {
        return true;
    }
}
