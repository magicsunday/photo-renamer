<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\DuplicateIdentifierProcessor\ContentHashIdentifierProcessor;
use MagicSunday\Renamer\FilenameProcessor\DefaultFilenameProcessor;
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
    protected function getTargetFilenameProcessor(): callable
    {
        return new DefaultFilenameProcessor();
    }

    #[Override]
    protected function getDuplicateIdentifierProcessor(): callable
    {
        return new ContentHashIdentifierProcessor();
    }
}
