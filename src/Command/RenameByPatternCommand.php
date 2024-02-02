<?php

/**
 * This file is part of the package magicsunday/renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use FilesystemIterator;
use MagicSunday\Renamer\Command\FilterIterator\FilenameFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/renamer/
 */
class RenameByPatternCommand extends BaseRenameCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:pattern')
            ->setDescription('Renames files by pattern.');

        $this
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'The pattern used to search for files',
                '/^(.+)(jpeg)$/'
            );

        $this
            ->addOption(
                'replacement',
                'r',
                InputOption::VALUE_REQUIRED,
                'The pattern used to replace the matches results',
                '\$1jpg'
            );

        // TODO Remove not required 'target-filename-pattern' option
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
        parent::execute($input, $output);

        if ($input->getOption('replacement') === null) {
            $this->io->error('A valid replacement value is required');
            return self::FAILURE;
        }

        $sourceDirectory = $input->getArgument('source-directory');

        $this->io->note(
            sprintf('Process files in: %s', $sourceDirectory)
        );

        try {
            $this->processDirectory(
                $input->getOption('dry-run'),
                $input->getOption('copy-files'),
                $input->getOption('pattern'),
                $input->getOption('replacement'),
                $sourceDirectory,
                $input->getArgument('target-directory')
            );
        } catch (RuntimeException $exception) {
            $this->io->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->io->success('done');

        return self::SUCCESS;
    }

    /**
     * @param bool        $dryRun
     * @param bool        $copyFiles
     * @param string      $pattern
     * @param string      $replacement
     * @param string      $sourceDirectory
     * @param string|null $targetDirectory
     *
     * @return void
     *
     * @throws RuntimeException
     */
    public function processDirectory(
        bool $dryRun,
        bool $copyFiles,
        string $pattern,
        string $replacement,
        string $sourceDirectory,
        ?string $targetDirectory = null
    ): void {
        $directoryIterator      = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $filenameFilterIterator = new FilenameFilterIterator($directoryIterator, $pattern);
        $iterator               = new RecursiveIteratorIterator($filenameFilterIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            // Perform regular expression replacement
            $newFilename = preg_replace(
                $pattern,
                $replacement,
                $fileInfo->getFilename()
            );

            if ($targetDirectory !== null) {
                $newPathname = $targetDirectory . '/' . $fileInfo->getPath() . '/' . $newFilename;
            } else {
                $newPathname = $fileInfo->getPath() . '/' . $newFilename;
            }

            $this->io->text('Rename ' . $fileInfo->getPathname() . ' to ' . $newPathname);

            if (file_exists($newPathname)) {
                $this->io->text('=> Skipping. Filename already exists.');
                continue;
            }

            if ($dryRun === false) {
                $this->renameFile($newPathname, $fileInfo, $copyFiles);
            }
        }
    }
}
