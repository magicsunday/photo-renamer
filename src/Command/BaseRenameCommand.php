<?php

/**
 * This file is part of the package magicsunday/renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function dirname;

/**
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/renamer/
 */
abstract class BaseRenameCommand extends Command
{
    /**
     * @var SymfonyStyle
     */
    protected SymfonyStyle $io;

    /**
     *
     */
    protected function configure(): void
    {
        $this
            ->addArgument(
                'source-directory',
                InputArgument::REQUIRED,
                'Source directory with photos.'
            )
            ->addArgument(
                'target-directory',
                InputArgument::OPTIONAL,
                'Target directory with photos. If this argument is omitted, the operation '
                    . 'takes place directly in the source directory.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Perform a dry run, without actually changing anything.'
            )
            ->addOption(
                'copy-files',
                null,
                InputOption::VALUE_NONE,
                'Copies the files to the target directory instead of renaming/moving them directly.'
            )
            ->addOption(
                'skip-duplicates',
                null,
                InputOption::VALUE_NONE,
                'Skip duplicate files from copy/rename action. The files remain unchanged in the source directory.'
            )
            ->addOption(
                'target-filename-pattern',
                'fp',
                InputOption::VALUE_REQUIRED,
                'The date pattern used to create the target filename.',
                'Y-m-d_H-i-s-v'
            );
//            ->addOption(
//                'skipped-suffix',
//                null,
//                InputOption::VALUE_OPTIONAL,
//                'Suffix that is appended to all skipped files before the file extension.',
//                '.skipped'
//            )
    }
    /**
     * Executes the current command.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getName());

        if (
            $input->getOption('copy-files')
            && ($input->getArgument('target-directory') === null)
        ) {
            $this->io->error('Copying files requires a target directory');
            return self::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $this->io->info('Performing dry run');
        }

        return self::SUCCESS;
    }

    /**
     * Renames or copies a file with its new filename.
     *
     * @param string      $newPathname
     * @param SplFileInfo $fileInfo
     * @param bool        $copyFiles
     *
     * @return void
     *
     * @throws RuntimeException
     */
    protected function renameFile(string $newPathname, SplFileInfo $fileInfo, bool $copyFiles): void
    {
        $copyTargetDirectory = dirname($newPathname);

        if (
            !file_exists($copyTargetDirectory)
            && !mkdir($copyTargetDirectory, 0775, true)
            && !is_dir($copyTargetDirectory)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $copyTargetDirectory
                )
            );
        }

        if (
            file_exists($fileInfo->getPathname())
            && (!file_exists($newPathname) || is_writable($newPathname))
        ) {
            if ($copyFiles) {
                // Copies files from source to target with renaming
                copy($fileInfo->getPathname(), $newPathname);
            } else {
                // Moves files from source to target (removes files in source)
                rename($fileInfo->getPathname(), $newPathname);
            }
        } else {
            throw new RuntimeException(
                sprintf(
                    'Target file "%s" is not writeable',
                    $newPathname
                )
            );
        }
    }
}
