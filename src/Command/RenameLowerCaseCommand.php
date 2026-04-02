<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\LowerCaseFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;

/**
 * Converts all filenames in the source directory to lowercase using multibyte-safe
 * conversion. Groups by full target pathname to detect collisions when two files
 * differ only in casing (e.g. "Photo.JPG" and "photo.jpg").
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RenameLowerCaseCommand extends AbstractRenameCommand
{
    private ?RenameStrategyInterface $renameStrategy = null;

    private ?DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy = null;

    /**
     * Configures the current command.
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:lower')
            ->setDescription(
                'Converts filenames to lowercase.'
            );
    }

    /**
     * Returns the target filename strategy.
     *
     * Uses the LowerCaseFilenameStrategy to convert filenames to lowercase.
     *
     * @return RenameStrategyInterface The rename strategy
     */
    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        return $this->renameStrategy ??= new LowerCaseFilenameStrategy();
    }

    /**
     * Returns the duplicate identifier strategy.
     *
     * Uses the TargetPathnameStrategy to group files by their full target path.
     *
     * @return DuplicateIdentifierStrategyInterface The duplicate identifier strategy
     */
    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return $this->duplicateIdentifierStrategy ??= new TargetPathnameStrategy();
    }
}
