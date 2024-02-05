<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use DateTime;
use Exception;
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
use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByExifDateCommand extends BaseRenameCommand
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
            ->setName('rename:exifdate')
            ->setDescription(
                'Renames files with EXIF data field "DateTimeOriginal" (incl. Apple Live Photos). '
                . 'All files without EXIF data remain unchanged in the source directory.'
            );

        $this
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
                'Y-m-d_H-i-s'
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
                $input->getOption('target-filename-pattern'),
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
     * @param string      $targetFilenamePattern
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
        bool $skipDuplicates,
        string $targetFilenamePattern,
        string $sourceDirectory,
        ?string $targetDirectory = null
    ): void {
        $directoryIterator = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $iterator          = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        $filesFrom = $this->groupFilesByPathname($iterator);
        $filesFrom = $this->extractExifData($filesFrom);

        $filesMerged = $this->mergeFilesByNewTargetFilename($filesFrom, $targetFilenamePattern);
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
    protected function groupFilesByPathname(RecursiveIteratorIterator $recursiveIteratorIterator): FileInfoCollection
    {
        $this->io->note('Group files by their pathname (ignoring the file extension)');

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
    protected function extractExifData(FileInfoCollection $fileInfoCollection): FileInfoCollection
    {
        $this->io->note('Extract the EXIF data');

        /** @var FileInfo $fileGroupData */
        foreach ($fileInfoCollection as $fileGroupData) {
            $exifData = false;

            foreach ($fileGroupData->getFiles() as $fileInfo) {
                // Look up EXIF data in the file list
                $exifData = @exif_read_data($fileInfo->getPathname());

                if ($exifData !== false) {
                    $this->io->text('Extract EXIF data from: ' . $fileInfo->getPathname());
                    break;
                }
            }

            // Ignore files without EXIF data
            if ($exifData === false) {
                continue;
            }

            if (!isset($exifData['DateTimeOriginal'])) {
                continue;
            }

            $this->io->text('=> Found "DateTimeOriginal" => ' . $exifData['DateTimeOriginal']);

            //            try {
            //                $dateTimeOriginal = new DateTime($exifData['DateTimeOriginal']);
            //
            //                if (isset($exifData['SubSecTimeOriginal'])) {
            //                    var_dump($exifData['SubSecTimeOriginal']);
            //                    $dateTimeOriginal->modify('+' . ($exifData['SubSecTimeOriginal']) . ' Milliseconds');
            //                }

            // Store the date and time the image/video was recorded
            $fileGroupData->setExifDateTimeOriginal($exifData['DateTimeOriginal']);
            $fileGroupData->setExifSubSecTimeOriginal($exifData['SubSecTimeOriginal'] ?? '');
            //            } catch (Exception) {
            //                $this->io->warning('=> Invalid EXIF date format in "DateTimeOriginal".');
            //            }
        }

        return $fileInfoCollection;
    }

    /**
     * Group all files with EXIF data into a new array. Creates the target filename from the file capture date
     * (extracted from the EXIF data) and merges all files that originally have the same or a different filename
     * but the same file capture date.
     *
     * @param FileInfoCollection $fileInfoCollection
     * @param string             $targetFilenamePattern
     *
     * @return FileDuplicateCollection
     */
    protected function mergeFilesByNewTargetFilename(
        FileInfoCollection $fileInfoCollection,
        string $targetFilenamePattern
    ): FileDuplicateCollection {
        $this->io->note('Create list of files based on extracted EXIF data');

        $fileDuplicateCollection = new FileDuplicateCollection();

        /** @var FileInfo $fileGroupData */
        foreach ($fileInfoCollection as $fileGroupData) {
            // Ignore all file groups without any EXIF data
            if ($fileGroupData->getExifDateTimeOriginal() === '') {
                continue;
            }

            try {
                $dateTimeOriginal = new DateTime($fileGroupData->getExifDateTimeOriginal());

                if ($fileGroupData->getExifSubSecTimeOriginal() !== '') {
                    if (strlen($fileGroupData->getExifSubSecTimeOriginal()) > 4) {
                        $dateTimeOriginal->modify('+' . $fileGroupData->getExifSubSecTimeOriginal() . ' Microseconds');
                    } else {
                        $dateTimeOriginal->modify('+' . $fileGroupData->getExifSubSecTimeOriginal() . ' Milliseconds');
                    }
                }
            } catch (Exception) {
                $this->io->warning('=> Invalid EXIF date format in "DateTimeOriginal".');
            }

            foreach ($fileGroupData->getFiles() as $fileInfo) {
                $hasSubSeconds = $fileGroupData->getExifSubSecTimeOriginal() !== '';

                if ($hasSubSeconds) {
                    // Create a new file name based on EXIF data "DateTimeOriginal" (the Date/Time the image was recorded)
                    $targetBasename = $dateTimeOriginal->format($targetFilenamePattern . '-v');
                } else {
                    $targetBasename = $dateTimeOriginal->format($targetFilenamePattern);
                }

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
