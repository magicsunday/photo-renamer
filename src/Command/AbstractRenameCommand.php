<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
abstract class AbstractRenameCommand extends Command
{
    /**
     * @var string
     */
    private const DUPLICATE_IDENTIFIER = '-duplicate-';

    /**
     * @var SymfonyStyle
     */
    protected SymfonyStyle $io;

    /**
     * Set to TRUE to use the file extension from the current processed source file.
     *
     * @var bool
     */
    protected bool $useFileExtensionFromSource = false;

    /**
     * Configures the current command.
     *
     * @return void
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
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getName() ?? '');

        /** @var bool $copyFiles */
        $copyFiles = $input->getOption('copy-files');

        /** @var bool $dryRun */
        $dryRun = $input->getOption('dry-run');

        /** @var bool $skipDuplicates */
        $skipDuplicates = $input->getOption('skip-duplicates');

        if (
            $copyFiles
            && ($input->getArgument('target-directory') === null)
        ) {
            $this->io->error('Copying files requires a target directory');

            return self::FAILURE;
        }

        if (
            $skipDuplicates
            && ($input->getArgument('target-directory') === null)
        ) {
            $this->io->error('Skipping duplicate file requires a target directory');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->io->info('Performing dry run');
        } elseif (!$this->io->confirm('This will rename all files in the selected directory. Are you sure?', false)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Returns the number of files the iterator matches.
     *
     * @param RecursiveIteratorIterator $iterator
     *
     * @return int
     */
    protected function countFiles(RecursiveIteratorIterator $iterator): int
    {
        $fileCount = 0;

        foreach ($iterator as $ignored) {
            ++$fileCount;
        }

        return $fileCount;
    }

    /**
     * Remove any existing "-duplicate-000" identifier.
     *
     * @param string $filename
     *
     * @return string
     */
    protected function removeDuplicateIdentifier(string $filename): string
    {
        return preg_replace(
            '/' . self::DUPLICATE_IDENTIFIER . '\d{3}$/',
            '',
            $filename
        ) ?? $filename;
    }

    /**
     * Returns the target pathname.
     *
     * @param SplFileInfo $splFileInfo
     * @param string      $targetFilename
     * @param string      $sourceDirectory
     * @param string      $targetDirectory
     *
     * @return string
     */
    protected function getTargetPathname(
        SplFileInfo $splFileInfo,
        string $targetFilename,
        string $sourceDirectory,
        string $targetDirectory,
    ): string {
        $targetPathname = $targetDirectory . '/'
            . trim(str_replace($sourceDirectory, '', $splFileInfo->getPath()), '/');

        return rtrim($targetPathname, '/') . '/' . $targetFilename;
    }

    /**
     * Creates a consecutive new filename for all duplicate files. The order of the duplicate files
     * is the same as in the input "files" array.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     * @param string                  $sourceDirectory
     * @param string                  $targetDirectory
     *
     * @return FileDuplicateCollection
     */
    protected function createDuplicateFilenames(
        FileDuplicateCollection $fileDuplicateCollection,
        string $sourceDirectory,
        string $targetDirectory,
    ): FileDuplicateCollection {
        $this->io->text('Create list of duplicate files');
        $this->io->newLine();
        $this->io->progressStart($fileDuplicateCollection->count());

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getFiles() as $renameSourceFileInfo) {
                $renameTargetFileExtension = $fileDuplicate->getTarget()->getExtension();

                // Modify the target file extension if the file extension from source should be used.
                // This allows use to rename different file types but with the same name.
                if ($this->useFileExtensionFromSource) {
                    $renameTargetFileExtension = $renameSourceFileInfo->getExtension();
                }

                $targetPathname = $this->getTargetPathname(
                    $renameSourceFileInfo,
                    $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension())
                        . '.' . $renameTargetFileExtension,
                    $sourceDirectory,
                    $targetDirectory
                );

                $renameTargetFileInfo = new SplFileInfo($targetPathname);

                $fileDuplicate->addRename(
                    new Rename(
                        $renameSourceFileInfo,
                        $renameTargetFileInfo
                    )
                );
            }

            $renames = $fileDuplicate->getRenames();

            // Remove elements where the source already equals the target
            foreach ($renames as $key => $rename) {
                if ($rename->getSource()->getPathname() === $rename->getTarget()->getPathname()) {
                    unset($renames[$key]);
                    break;
                }
            }

            $fileDuplicate->setRenames(array_values($renames));

            $duplicateCount = 1;

            // Check if the target file already exists in the file system, so we need to adjust
            // the new target name again.
            foreach ($fileDuplicate->getRenames() as $index => $rename) {
                $rename->setTarget(
                    $this->createDuplicateTargetFileInfo(
                        $rename->getSource(),
                        $rename->getTarget(),
                        $duplicateCount,
                        $index === 0
                    )
                );
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * @param SplFileInfo $source
     * @param SplFileInfo $target
     * @param int         $duplicateCount
     * @param bool        $isFirst
     *
     * @return SplFileInfo
     */
    private function createDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        bool $isFirst = false
    ): SplFileInfo {
        $duplicateBasename = $target->getBasename('.' . $target->getExtension());

        if ($target->isFile()) {
            if ($source->getPathname() !== $target->getPathname()) {
                return $this->getNewUniqueDuplicateTargetFileInfo(
                    $source,
                    $target,
                    $duplicateBasename,
                    $duplicateCount
                );
            }

            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount
            );
        }

        if (!$isFirst) {
            return $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount
            );
        }

        return $target;
    }

    /**
     * @param SplFileInfo $source
     * @param SplFileInfo $target
     * @param string      $targetBasename
     * @param int         $duplicateCount
     *
     * @return SplFileInfo
     */
    private function getNewUniqueDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
    ): SplFileInfo {
        $duplicateFileInfo = $target;

        while ($duplicateFileInfo->isFile()) {
            $duplicateFileInfo = $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $targetBasename,
                $duplicateCount
            );
        }

        return $duplicateFileInfo;
    }

    /**
     * Returns a new file info object with a unique filename.
     *
     * @param SplFileInfo $source
     * @param SplFileInfo $target
     * @param string      $targetBasename
     * @param int         $duplicateCount
     *
     * @return SplFileInfo
     */
    private function getNewDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
    ): SplFileInfo {
        $newTargetBasename = sprintf(
            '%s' . self::DUPLICATE_IDENTIFIER . '%003d',
            $targetBasename,
            $duplicateCount
        );

        $targetPathname = $this->getTargetPathname(
            $source,
            $newTargetBasename . '.' . $target->getExtension(),
            $source->getPath(),
            $target->getPath()
        );

        ++$duplicateCount;

        return new SplFileInfo($targetPathname);
    }

    /**
     * Renames all the files.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     * @param bool                    $dryRun
     * @param bool                    $copyFiles
     * @param bool                    $skipDuplicates
     *
     * @return void
     *
     * @throws RuntimeException
     */
    protected function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
    ): void {
        $this->io->text(($copyFiles ? 'Copying' : 'Renaming') . ' files');
        $this->io->newLine();
        //        $this->io->progressStart($fileDuplicateCollection->count());

        $maxFilenameLength = 0;
        $fileCount         = 0;
        $duplicateCount    = 0;

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                if (strlen($rename->getSource()->getPathname()) > $maxFilenameLength) {
                    $maxFilenameLength = strlen($rename->getSource()->getPathname());
                }
            }
        }

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $this->io->text(
                    sprintf(
                        'Rename %-' . $maxFilenameLength . 's to %s',
                        $rename->getSource()->getPathname(),
                        $rename->getTarget()->getPathname()
                    )
                );

                if (str_contains($rename->getTarget()->getFilename(), self::DUPLICATE_IDENTIFIER)) {
                    ++$duplicateCount;
                }

                if (
                    $skipDuplicates
                    && str_contains($rename->getTarget()->getFilename(), self::DUPLICATE_IDENTIFIER)
                ) {
                    $this->io->text('=> Duplicate! Skip "' . $rename->getSource()->getPathname() . '"');
                    continue;
                }

                ++$fileCount;

                if ($dryRun === false) {
                    $this->renameFile(
                        $rename->getSource(),
                        $rename->getTarget(),
                        $copyFiles
                    );
                }
            }

            //            $this->io->progressAdvance();
        }

        //        $this->io->progressFinish();
        //        $this->io->newLine();
        $this->io->info($duplicateCount . ' possible duplicates found');
        $this->io->info($fileCount . ' files renamed');
    }

    /**
     * Renames or copies a file with its new filename.
     *
     * @param SplFileInfo $sourceFileInfo
     * @param SplFileInfo $targetFileInfo
     * @param bool        $copyFiles
     *
     * @return void
     */
    private function renameFile(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo, bool $copyFiles): void
    {
        $copyTargetDirectory = $targetFileInfo->getPath();

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
            $sourceFileInfo->isFile()
            && (!$targetFileInfo->isFile() || $targetFileInfo->isWritable())
        ) {
            if ($copyFiles) {
                // Copies a file from source to target with renaming
                copy($sourceFileInfo->getPathname(), $targetFileInfo->getPathname());
            } else {
                // Moves a file from source to target (removes a file in source)
                rename($sourceFileInfo->getPathname(), $targetFileInfo->getPathname());
            }
        } else {
            throw new RuntimeException(
                sprintf(
                    'Target file "%s" is not writeable',
                    $targetFileInfo->getPathname()
                )
            );
        }
    }
}
