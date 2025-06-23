<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\DuplicateIdentifierProcessor\TargetPathnameIdentifierProcessor;
use MagicSunday\Renamer\FilenameProcessor\LowerCaseFilenameProcessor;
use Override;

/**
 * Recursively renames all files in the specified directory to lowercase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameLowerCaseCommand extends AbstractRenameCommand
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
            ->setName('rename:lower')
            ->setDescription(
                'Changes all filenames containing at least one uppercase letter to lowercase. '
                . 'By default, the renaming occurs in the same directory unless specified.'
            );
    }

    #[Override]
    protected function getTargetFilenameProcessor(): callable
    {
        return new LowerCaseFilenameProcessor();
    }

    #[Override]
    protected function getDuplicateIdentifierProcessor(): callable
    {
        return new TargetPathnameIdentifierProcessor();
    }
}
