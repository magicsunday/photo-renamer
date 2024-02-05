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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recursivly renames all files in the specified directory to lowercase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameLowerCaseCommand extends BaseRenameCommand
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
            ->setName('rename:lower')
            ->setDescription(
                'Renames the file name to lowercase. By default, renaming occurs in the same'
                . ' directory unless the appropriate options have been specified.'
            );
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

        /** @var string $sourceDirectory */
        $sourceDirectory = $input->getArgument('source-directory');

        $this->io->note(
            sprintf('Process files in: %s', $sourceDirectory)
        );

        try {
            $this->processDirectory(
                $input->getOption('dry-run'),
                $input->getOption('copy-files'),
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
        string $sourceDirectory,
        ?string $targetDirectory = null
    ): void {
        $directoryIterator = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $iterator          = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::LEAVES_ONLY);
        $fileCount         = 0;

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $newFilename = strtolower($fileInfo->getFilename());

            if ($targetDirectory !== null) {
                $newPathname = $targetDirectory . '/' . $fileInfo->getPath() . '/' . $newFilename;
            } else {
                $newPathname = $fileInfo->getPath() . '/' . $newFilename;
            }

            if (file_exists($newPathname)) {
                continue;
            }

            $this->io->text('Rename ' . $fileInfo->getPathname() . ' to ' . $newPathname);

            ++$fileCount;

            if ($dryRun === false) {
                $this->renameFile($newPathname, $fileInfo, $copyFiles);
            }
        }

        $this->io->info($fileCount . ' files renamed');
    }
}
