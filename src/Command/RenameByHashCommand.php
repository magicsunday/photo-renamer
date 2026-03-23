<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

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

    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        private readonly SafeHashCalculatorInterface $hashCalculator,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService);
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

    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        return $this->renameStrategy ??= new InheritFilenameStrategy();
    }

    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return $this->duplicateIdentifierStrategy ??= new ContentHashStrategy($this->hashCalculator);
    }

    #[Override]
    protected function skipHashSubGrouping(): bool
    {
        return true;
    }
}
