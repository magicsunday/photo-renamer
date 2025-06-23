<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use FilesystemIterator;
use MagicSunday\Renamer\Command\FilterIterator\RecursiveRegexFileFilterIterator;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;

/**
 * Recursively renames all files matching a given pattern. The renaming is defined by the given "replacement" pattern.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByPatternCommand extends AbstractRenameCommand
{
    /**
     * @var string
     */
    private string $pattern = '';

    /**
     * @var string
     */
    private string $replacement = '';

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
            ->setName('rename:pattern')
            ->setDescription('Renames files by pattern.')
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'The pattern used to search for files',
                '/^(.+)(jpeg)$/'
            )
            ->addOption(
                'replacement',
                'r',
                InputOption::VALUE_REQUIRED,
                'The pattern used to replace the matches results',
                '\$1jpg'
            );
    }

    #[Override]
    protected function executeCommand(): int
    {
        if ($this->input->getOption('replacement') === null) {
            $this->io->error('A valid replacement value is required');

            return self::FAILURE;
        }

        $this->pattern     = $this->input->getOption('pattern');
        $this->replacement = $this->input->getOption('replacement');

        return parent::executeCommand();
    }

    #[Override]
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveRegexFileFilterIterator(
                new RecursiveDirectoryIterator(
                    $this->sourceDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                $this->pattern
            )
        );
    }

    #[Override]
    protected function getTargetFilename(SplFileInfo $sourceFileInfo): ?string
    {
        $targetBasename = $this->removeDuplicateFileIdentifier(
            $sourceFileInfo->getBasename('.' . $sourceFileInfo->getExtension())
        );

        // Perform the regular expression replacement
        $targetFilename = preg_replace(
            $this->pattern,
            $this->replacement,
            $targetBasename . '.' . $sourceFileInfo->getExtension()
        );

        if ($targetFilename === null) {
            $this->io->error(preg_last_error_msg());
        }

        return $targetFilename;
    }

    #[Override]
    protected function getUniqueDuplicateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        // We want to find duplicates in the current directory,
        // so the unique identifier must also contain the path.
        return $targetFileInfo->getPathname();
    }
}
