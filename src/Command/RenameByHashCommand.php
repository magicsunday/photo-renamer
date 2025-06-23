<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\ContentHashStrategy;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;

/**
 * Recursively detects duplicates in the specified directory matching the same file hash.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByHashCommand extends AbstractRenameCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:hash')
            ->setDescription(
                'Detects duplicate files matching the same file hash.'
            );
    }

    #[Override]
    protected function getTargetFilenameProcessor(): RenameStrategyInterface
    {
        return new InheritFilenameStrategy();
    }

    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return new ContentHashStrategy();
    }
}
