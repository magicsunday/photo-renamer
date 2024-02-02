<?php

/**
 * This file is part of the package magicsunday/renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use DateTime;
use Exception;
use FilesystemIterator;
use MagicSunday\Renamer\Model\Collection\FileInfoCollection;
use MagicSunday\Renamer\Model\FileInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_key_exists;
use function in_array;
use function strlen;

/**
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/renamer/
 */
class RenameLivePhotosCommand extends BaseRenameCommand
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
            ->setName('rename:live-photos')
            // the command description shown when running "php bin/console list"
            ->setDescription(
                'Renames files with EXIF data field "DateTimeOriginal" (incl. Apple Live Photos.). '
                . 'All files without EXIF data remain unchanged in the source directory.'
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
     * @param bool            $dryRun
     * @param bool            $copyFiles
     * @param bool            $skipDuplicates
     * @param string          $targetFilenamePattern
     * @param string          $sourceDirectory
     * @param string|null     $targetDirectory
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

        $filesFrom   = $this->groupFilesByPathname($iterator);
        $filesFrom   = $this->extractExifData($filesFrom);
        $filesMerged = $this->mergeFilesByNewTargetFilename($filesFrom, $targetFilenamePattern);
        $filesMerged = $this->createDuplicateBasename($filesMerged);

        $this->renameFiles(
            $filesMerged,
            $targetFilenamePattern,
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
     * @return array
     */
//    protected function groupFilesByPathname(RecursiveIteratorIterator $recursiveIteratorIterator): FileInfoCollection
    protected function groupFilesByPathname(RecursiveIteratorIterator $recursiveIteratorIterator): array
    {
        $this->io->note('Group files by their pathname (ignoring the file extension)');

        $filesGrouped = [];

//        $fileInfoCollection = new FileInfoCollection();

        /** @var SplFileInfo $fileInfo */
        foreach ($recursiveIteratorIterator as $fileInfo) {
            $this->io->text('Process: ' . $fileInfo->getPathname());

            // Remove the file extension from the pathname
            $pathnameWithoutExt = substr(
                $fileInfo->getPathname(),
                0,
                -strlen('.' . $fileInfo->getExtension())
            );

            if (!array_key_exists($pathnameWithoutExt, $filesGrouped)) {
                $filesGrouped[$pathnameWithoutExt] = [
                    'path'  => $fileInfo->getPath(),
                    'files' => [],
                ];
            }

            // Append all files with the same pathname into one array
            $filesGrouped[$pathnameWithoutExt]['files'][] = $fileInfo;

//            if ($fileInfoCollection->offsetExists($pathnameWithoutExt)) {
//                $file = $fileInfoCollection->offsetGet($pathnameWithoutExt);
//            } else {
//                $file = new FileInfo();
//                $file->setPath($fileInfo->getPath());
//
//                $fileInfoCollection->offsetSet($pathnameWithoutExt, $file);
//            }
//
//            $file->addFile($fileInfo);

        }

//ini_set('xdebug.var_display_max_depth', '-1');
//ini_set('xdebug.var_display_max_children', '-1');
//ini_set('xdebug.var_display_max_data', '-1');

//var_dump($filesGrouped);
//var_dump($fileInfoCollection);

//        return $fileInfoCollection;

        return $filesGrouped;
    }

    /**
     * Extract the EXIF data for all files in the given list.
     *
     * @param array $filesGrouped
     *
     * @return array
     */
    protected function extractExifData(array $filesGrouped): array
    {
        $this->io->note('Extract the EXIF data');

        foreach ($filesGrouped as $pathnameWithoutExt => $fileGroupData) {
            $exifData = false;

            /** @var SplFileInfo $fileInfo */
            foreach ($fileGroupData['files'] as $fileInfo) {
                // Look up EXIF data in the file list
                $exifData = @exif_read_data($fileInfo->getPathname(), 'EXIF');

                if ($exifData !== false) {
                    $this->io->text('Extract EXIF data from: ' . $fileInfo->getPathname());
                    break;
                }
            }

            // Ignore files without EXIF data
            if (
                ($exifData === false)
                || (!isset($exifData['DateTimeOriginal']))
            ) {
                continue;
            }

            $this->io->text('=> Found "DateTimeOriginal" => ' . $exifData['DateTimeOriginal']);

            try {
                $dateTimeOriginal = new DateTime($exifData['DateTimeOriginal']);

                if (isset($exifData['SubSecTimeOriginal'])) {
                    $dateTimeOriginal->modify('+' . ($exifData['SubSecTimeOriginal']) . ' Milliseconds');
                }

                // Store the date and time the image/video was recorded
                $filesGrouped[$pathnameWithoutExt]['exif']['dateTimeOriginal'] = $dateTimeOriginal;
            } catch (Exception) {
                $this->io->warning('=> Invalid EXIF date format in "DateTimeOriginal".');
            }
        }

        return $filesGrouped;
    }

    /**
     * Group all files with EXIF data into a new array. Creates the target filename from the file capture date
     * (extracted from the EXIF data) and merges all files that originally have the same or a different filename
     * but the same file capture date.
     *
     * @param array  $filesGrouped
     * @param string $targetFilenamePattern
     *
     * @return array
     */
    protected function mergeFilesByNewTargetFilename(array $filesGrouped, string $targetFilenamePattern): array
    {
        $this->io->note('Create list of files based on extracted EXIF data');

        $filesMerged = [];

        foreach ($filesGrouped as $fileGroupData) {
            // Ignore all file groups without any EXIF data
            if (!isset($fileGroupData['exif']['dateTimeOriginal'])) {
                continue;
            }

            /** @var SplFileInfo $fileInfo */
            foreach ($fileGroupData['files'] as $fileInfo) {
                // Create new file name based on EXIF data "DateTimeOriginal", Date/Time of image recorded
                $targetBasename = $fileGroupData['exif']['dateTimeOriginal']->format($targetFilenamePattern);
                $targetFilename = $targetBasename . '.' . $fileInfo->getExtension();

                if (!isset($filesMerged[$targetFilename])) {
                    $filesMerged[$targetFilename] = [
                        'path'      => $fileGroupData['path'],
                        'basename'  => $targetBasename,
                        'extension' => $fileInfo->getExtension(),
                        'files'     => [],
                    ];
                }

                // Append all files with the same target filename into one array
                $filesMerged[$targetFilename]['files'][] = $fileInfo;
            }
        }

        return $filesMerged;
    }

    /**
     * Creates a consecutive new filename for all duplicate files. The order of the duplicate files
     * is the same as in the input "files" array.
     *
     * @param array $filesGrouped
     *
     * @return array
     */
    protected function createDuplicateBasename(array $filesGrouped): array
    {
        $this->io->note('Create list of duplicate files');

        foreach ($filesGrouped as $key => $fileGroupData) {
            $fileCount = count($fileGroupData['files']);

            // Handle possible duplicate files, after renaming
            for ($file = 0; $file < $fileCount; ++$file) {
                if (!isset($filesGrouped[$key]['new'])) {
                    $filesGrouped[$key]['new'] = [];
                }

                if (in_array($fileGroupData['basename'], $filesGrouped[$key]['new'], true)) {
                    $duplicateFileName = $fileGroupData['basename'];
                    $duplicateCount    = 0;

                    while (in_array($duplicateFileName, $filesGrouped[$key]['new'], true)) {
                        ++$duplicateCount;

                        $duplicateFileName = sprintf(
                            '%s-duplicate-%003d',
                            $fileGroupData['basename'],
                            $duplicateCount
                        );
                    }

                    $filesGrouped[$key]['new'][] = $duplicateFileName;
                } else {
                    $filesGrouped[$key]['new'][] = $fileGroupData['basename'];
                }
            }
        }

        return $filesGrouped;
    }

    /**
     * Renames all the files.
     *
     * @param array       $filesGrouped
     * @param string      $targetFilenamePattern
     * @param bool        $dryRun
     * @param bool        $copyFiles
     * @param bool        $skipDuplicates
     * @param null|string $targetDirectory
     *
     * @return void
     *
     * @throws RuntimeException
     */
    protected function renameFiles(
        array $filesGrouped,
        string $targetFilenamePattern,
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
        ?string $targetDirectory = null
    ): void {
        $this->io->note('Start renaming files to new filenames');

        foreach ($filesGrouped as $fileGroupData) {
            /** @var SplFileInfo $fileInfo */
            foreach ($fileGroupData['files'] as $key => $fileInfo) {
                $newFilename = $fileGroupData['new'][$key] . '.' . strtolower($fileGroupData['extension']);

                if ($targetDirectory !== null) {
                    $newPathname = $targetDirectory . '/' . $fileGroupData['path'] . '/' . $newFilename;
                } else {
                    $newPathname = $fileInfo->getPath() . '/' . $newFilename;
                }

                $this->io->text('Rename ' . $fileInfo->getPathname() . ' to ' . $newPathname);

                if (file_exists($newPathname)) {
                    $this->io->text('=> Skipping. Filename already exists.');
                    continue;
                }

                if (
                    $skipDuplicates
                    && str_contains($newFilename, '-duplicate-')
                ) {
                    $this->io->text('=> Duplicate! Skip "' . $fileInfo->getPathname() . '"');
                    continue;
                }

                if ($dryRun === false) {
                    $this->renameFile($newPathname, $fileInfo, $copyFiles);
                }
            }
        }
    }
}
