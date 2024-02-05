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
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileInfoCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\FileInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;

/**
 * Recursivly detects duplicates in the specified directory matching the same filesize.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByFilesizeCommand extends BaseRenameCommand
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
            ->setName('rename:duplicate:filesize')
            ->setDescription(
                'Detects duplicate files matching the same filesize.'
            );

        $this
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
                $input->getOption('skip-duplicates'),
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
     * @param bool        $skipDuplicates
     * @param string      $sourceDirectory
     * @param string|null $targetDirectory
     *
     * @return void
     */
    public function processDirectory(
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
        string $sourceDirectory,
        ?string $targetDirectory = null
    ): void {
        $directoryIterator = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $iterator          = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        $filesFrom = $this->groupFilesByFilesize($iterator);
        $filesFrom = $this->extractFilesize($filesFrom);

        $filesMerged = $this->mergeFilesByFilesize($filesFrom);
        $filesMerged = $this->createDuplicateBasename($filesMerged);

        $targetDirectory = $targetDirectory !== null
            ? trim($targetDirectory, '/')
            : $targetDirectory;

        $this->renameFiles(
            $filesMerged,
            $dryRun,
            $copyFiles,
            $skipDuplicates,
            $targetDirectory
        );
    }

    /**
     * Iterate over all files and group them by the pathname without the file extension, so
     * images and videos sharing the same name are grouped together.
     *
     * @param RecursiveIteratorIterator $recursiveIteratorIterator
     *
     * @return FileInfoCollection
     */
    protected function groupFilesByFilesize(RecursiveIteratorIterator $recursiveIteratorIterator): FileInfoCollection
    {
        $this->io->note('Group files by their filesize');

        $fileInfoCollection = new FileInfoCollection();

        /** @var SplFileInfo $fileInfo */
        foreach ($recursiveIteratorIterator as $fileInfo) {
            $this->io->text('Process: ' . $fileInfo->getPathname());

            // Remove the file extension from the pathname
            $pathnameWithoutExt = substr(
                $fileInfo->getPathname(),
                0,
                -strlen('.' . $fileInfo->getExtension())
            );

            $pathnameWithoutExt = $this->removeDuplicateIdentifier($pathnameWithoutExt)
                . '-' . $fileInfo->getSize();

            if ($fileInfoCollection->offsetExists($pathnameWithoutExt)) {
                /** @var FileInfo $file */
                $file = $fileInfoCollection->offsetGet($pathnameWithoutExt);
            } else {
                $file = new FileInfo();
                $file->setPath($fileInfo->getPath());

                $fileInfoCollection->offsetSet($pathnameWithoutExt, $file);
            }

            // Append all files with the same pathname into one array
            $file->addFile($fileInfo);
        }

        return $fileInfoCollection;
    }

    /**
     * Extract the EXIF data for all files in the given list.
     *
     * @param FileInfoCollection $fileInfoCollection
     *
     * @return FileInfoCollection
     */
    protected function extractFilesize(FileInfoCollection $fileInfoCollection): FileInfoCollection
    {
        $this->io->note('Extract the filesize');

        /** @var FileInfo $fileGroupData */
        foreach ($fileInfoCollection as $fileGroupData) {
            $filesize = false;

            foreach ($fileGroupData->getFiles() as $fileInfo) {
                $filesize = $fileInfo->getSize();

                if ($filesize !== false) {
                    $this->io->text('Extract filesize from: ' . $fileInfo->getPathname());
                    break;
                }
            }

            // Ignore files without EXIF data
            if ($filesize === false) {
                continue;
            }

            $this->io->text('=> Found filesize => ' . $filesize . ' bytes');

            // Store the filesize
            $fileGroupData->setFilesize($filesize);
        }

        return $fileInfoCollection;
    }

    /**
     * Group all files with EXIF data into a new array. Creates the target filename from the file capture date
     * (extracted from the EXIF data) and merges all files that originally have the same or a different filename
     * but the same file capture date.
     *
     * @param FileInfoCollection $fileInfoCollection
     *
     * @return FileDuplicateCollection
     */
    protected function mergeFilesByFilesize(FileInfoCollection $fileInfoCollection): FileDuplicateCollection
    {
        $this->io->note('Create list of files based on extracted filesize');

        $fileDuplicateCollection = new FileDuplicateCollection();

        /** @var FileInfo $fileGroupData */
        foreach ($fileInfoCollection as $fileGroupData) {
            // Ignore all file groups without any filesize
            if ($fileGroupData->getFilesize() === 0) {
                continue;
            }

            // Regroup files by their filesize
            foreach ($fileGroupData->getFiles() as $fileInfo) {
                $targetBasename = $this->removeDuplicateIdentifier(
                    $fileInfo->getBasename('.' . $fileInfo->getExtension())
                );

                $targetBasename = preg_replace('/-\d{9}$/', '', $targetBasename);

                $targetBasename = sprintf(
                    '%s-%000000009d',
                    $targetBasename,
                    $fileInfo->getSize()
                );

                // New target pathname (group by path and new target basename)
                $targetPathname = $fileInfo->getPath() . '/' . $targetBasename . '.' . $fileInfo->getExtension();

                if ($fileDuplicateCollection->offsetExists($targetPathname)) {
                    /** @var FileDuplicate $file */
                    $file = $fileDuplicateCollection->offsetGet($targetPathname);
                } else {
                    $file = new FileDuplicate();
                    $file
                        ->setPath($fileInfo->getPath())
                        ->setBasename($targetBasename)
                        ->setExtension($fileInfo->getExtension());

                    $fileDuplicateCollection->offsetSet($targetPathname, $file);
                }

                // Append all files with the same target filename into one array
                $file->addFile($fileInfo);
            }
        }

        return $fileDuplicateCollection;
    }

    /**
     * Creates a consecutive new filename for all duplicate files. The order of the duplicate files
     * is the same as in the input "files" array.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     *
     * @return FileDuplicateCollection
     */
    protected function createDuplicateBasename(
        FileDuplicateCollection $fileDuplicateCollection
    ): FileDuplicateCollection {
        $this->io->note('Create list of duplicate files');

        //        // Create a unique basename (multiple files may share the same name but with different filesize)
        //        $uniqueFileCollection = new FileDuplicateCollection();
        //
        //        /** @var FileDuplicate $fileGroupData */
        //        foreach ($fileDuplicateCollection as $fileGroupData) {
        //            if (count($fileGroupData->getFiles()) === 1) {
        //                continue;
        //            }
        //
        //            $duplicateCount = 1;
        //
        // //            $targetBasename = sprintf(
        // //                '%s-%000000009d',
        // //                $fileInfo->getSize()
        // //            )
        //            $targetBasename = sprintf(
        //                '%s-%0004d',
        //                $fileGroupData->getBasename(),
        //                $duplicateCount
        //            );
        //
        //            while ($uniqueFileCollection->offsetExists($targetBasename)) {
        //                ++$duplicateCount;
        //
        //                $targetBasename = sprintf(
        //                    '%s-%0004d',
        //                    $fileGroupData->getBasename(),
        //                    $duplicateCount
        //                );
        //            }
        //
        //            $fileGroupData
        //                ->setBasename($targetBasename);
        //
        //            $uniqueFileCollection->offsetSet(
        //                $targetBasename,
        //                $fileGroupData
        //            );
        //        }

        /** @var FileDuplicate $fileGroupData */
        foreach ($fileDuplicateCollection as $fileGroupData) {
            $fileCount = count($fileGroupData->getFiles());

            // Handle possible duplicate files, after renaming
            for ($file = 0; $file < $fileCount; ++$file) {
                $duplicateFileName = $fileGroupData->getBasename();
                $duplicateCount    = 0;

                while (in_array($duplicateFileName, $fileGroupData->getNew(), true)) {
                    ++$duplicateCount;

                    $duplicateFileName = sprintf(
                        '%s-duplicate-%003d',
                        $fileGroupData->getBasename(),
                        $duplicateCount
                    );
                }

                $fileGroupData->addNew($duplicateFileName);
            }
        }

        return $fileDuplicateCollection;
    }

    /**
     * Renames all the files.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     * @param bool                    $dryRun
     * @param bool                    $copyFiles
     * @param bool                    $skipDuplicates
     * @param string|null             $targetDirectory
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
        ?string $targetDirectory = null
    ): void {
        $this->io->note('Start renaming files to new filenames');

        $maxFilenameLength = 0;
        $fileCount         = 0;
        $duplicateCount    = 0;

        foreach ($fileDuplicateCollection as $fileGroupData) {
            foreach ($fileGroupData->getFiles() as $fileInfo) {
                if (strlen($fileInfo->getFilename()) > $maxFilenameLength) {
                    $maxFilenameLength = strlen($fileInfo->getFilename());
                }
            }
        }

        /** @var FileDuplicate $fileGroupData */
        foreach ($fileDuplicateCollection as $fileGroupData) {
            foreach ($fileGroupData->getFiles() as $key => $fileInfo) {
                $newFilename = $fileGroupData->getNew()[$key] . '.' . $fileGroupData->getExtension();

                if ($targetDirectory !== null) {
                    $newPathname = $targetDirectory . '/' . $fileGroupData->getPath() . '/' . $newFilename;
                } else {
                    $newPathname = $fileInfo->getPath() . '/' . $newFilename;
                }

                if (file_exists($newPathname)) {
                    continue;
                }

                $this->io->text(
                    sprintf(
                        'Rename %-' . $maxFilenameLength . 's to %s',
                        $fileInfo->getFilename(),
                        $newPathname
                    )
                );

                if (str_contains($newFilename, '-duplicate-')) {
                    ++$duplicateCount;
                }

                if (
                    $skipDuplicates
                    && str_contains($newFilename, '-duplicate-')
                ) {
                    $this->io->text('=> Duplicate! Skip "' . $fileInfo->getPathname() . '"');
                    continue;
                }

                ++$fileCount;

                if ($dryRun === false) {
                    $this->renameFile($newPathname, $fileInfo, $copyFiles);
                }
            }
        }

        $this->io->info($duplicateCount . ' possible duplicates found');
        $this->io->info($fileCount . ' files renamed');
    }
}
