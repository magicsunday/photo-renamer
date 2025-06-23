<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use Override;
use SplFileInfo;

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
    protected function getTargetFilename(SplFileInfo $sourceFileInfo): ?string
    {
        $targetBasename = $this->removeDuplicateFileIdentifier(
            $sourceFileInfo->getBasename('.' . $sourceFileInfo->getExtension())
        );

        return $targetBasename . '.' . $sourceFileInfo->getExtension();
    }

    #[Override]
    protected function getUniqueDuplicateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        // We want to find duplicates across all directories based on a hash of the file contents.
        return hash_file('xxh128', $sourceFileInfo->getPathname());
    }
}
