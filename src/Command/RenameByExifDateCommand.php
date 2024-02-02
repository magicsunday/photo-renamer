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
use MagicSunday\Renamer\Model\FileDuplicate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByExifDateCommand extends AbstractRenameCommand
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
            )
            ->addOption(
                'target-filename-pattern',
                'fp',
                InputOption::VALUE_REQUIRED,
                'The date pattern used to create the target filename.',
                'Y-m-d_H-i-s-v'
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
        $this->useFileExtensionFromSource = true;

        $parentResult = parent::execute($input, $output);

        if ($parentResult === self::FAILURE) {
            return self::FAILURE;
        }

        try {
            $this->processDirectory(
                $input->getOption('dry-run'),
                $input->getOption('copy-files'),
                $input->getOption('skip-duplicates'),
                $input->getOption('target-filename-pattern'),
                $input->getArgument('source-directory'),
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
    private function processDirectory(
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
        string $targetFilenamePattern,
        string $sourceDirectory,
        ?string $targetDirectory = null,
    ): void {
        $directoryIterator = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $iterator          = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        $sourceDirectory = rtrim($sourceDirectory, '/');

        // If target directory is empty, use source directory as target
        $targetDirectory = $targetDirectory !== null
            ? rtrim($targetDirectory, '/')
            : $sourceDirectory;

        // Process list of all files
        $fileDuplicateCollection = $this->groupFilesByTargetPathname(
            $iterator,
            $targetFilenamePattern,
            $sourceDirectory,
            $targetDirectory
        );

        $this->createDuplicateFilenames(
            $fileDuplicateCollection,
            $sourceDirectory,
            $targetDirectory
        );

        $this->renameFiles(
            $fileDuplicateCollection,
            $dryRun,
            $copyFiles,
            $skipDuplicates
        );
    }

    /**
     * Groups all the files matching the given pattern together by the resulting target file pathname.
     *
     * @param RecursiveIteratorIterator $iterator
     * @param string                    $targetFilenamePattern
     * @param string                    $sourceDirectory
     * @param string                    $targetDirectory
     *
     * @return FileDuplicateCollection
     */
    private function groupFilesByTargetPathname(
        RecursiveIteratorIterator $iterator,
        string $targetFilenamePattern,
        string $sourceDirectory,
        string $targetDirectory,
    ): FileDuplicateCollection {
        $this->io->text(sprintf('Process files in: %s', $sourceDirectory));
        $this->io->newLine();
        $this->io->progressStart($this->countFiles($iterator));

        $fileDuplicateCollection = new FileDuplicateCollection();

        /** @var SplFileInfo $splFileInfo */
        foreach ($iterator as $splFileInfo) {
            $exifDateFormatted = $this->getExifDateFormatted($targetFilenamePattern, $splFileInfo);

            // Ignore files without EXIF data
            if ($exifDateFormatted === null) {
                continue;
            }

            $targetFilename = $this->getTargetFilename(
                $exifDateFormatted,
                $splFileInfo
            );

            $targetPathname = $this->getTargetPathname(
                $splFileInfo,
                $targetFilename,
                $sourceDirectory,
                $targetDirectory
            );

            // Create a new target file object
            $targetFileInfo = new SplFileInfo($targetPathname);
            $collectionKey  = $exifDateFormatted . '.' . $splFileInfo->getExtension(); // $this->getPathNameWithoutExtension($splFileInfo);

            // Create duplicate object storing relevant data
            $fileDuplicate = new FileDuplicate();
            $fileDuplicate
                ->addFile($splFileInfo)
                ->setTarget($targetFileInfo);

            if ($fileDuplicateCollection->offsetExists($collectionKey)) {
                /** @var FileDuplicate $fileDuplicate */
                $fileDuplicate = $fileDuplicateCollection->offsetGet($collectionKey);
                $fileDuplicate->addFile($splFileInfo);
            } else {
                $fileDuplicateCollection->offsetSet($collectionKey, $fileDuplicate);
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
        $this->io->text('Perform a second pass to find all remaining files that share the same base name');
        $this->io->newLine();
        $this->io->progressStart($this->countFiles($iterator));

        // Perform a second iteration over all files and now add all files that are not yet included in the list
        foreach ($iterator as $splFileInfo) {
            // $this->io->text($splFileInfo->getPathname());
            foreach ($fileDuplicateCollection as $collectionKey => $fileDuplicate) {
                foreach ($fileDuplicate->getFiles() as $duplicateSplFileInfo) {
                    if ($splFileInfo->getPathname() === $duplicateSplFileInfo->getPathname()) {
                        break 2;
                    }

                    $sourceWithoutExtension = $this->getPathNameWithoutExtension($splFileInfo);
                    $targetWithoutExtension = $this->getPathNameWithoutExtension($duplicateSplFileInfo);

                    if ($sourceWithoutExtension === $targetWithoutExtension) {
                        $targetFileInfo = new SplFileInfo(
                            $splFileInfo->getPath()
                            . '/'
                            . $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension())
                            . '.'
                            . $splFileInfo->getExtension(),
                        );

                        // Create duplicate object storing relevant data
                        $fileDuplicate = new FileDuplicate();
                        $fileDuplicate
                            ->addFile($splFileInfo)
                            ->setTarget($targetFileInfo);

                        $collectionKey = substr($collectionKey, 0, -strlen('.' . $splFileInfo->getExtension()))
                            . '.' . $targetFileInfo->getExtension();

                        if ($fileDuplicateCollection->offsetExists($collectionKey)) {
                            /** @var FileDuplicate $fileDuplicate */
                            $fileDuplicate = $fileDuplicateCollection->offsetGet($collectionKey);
                            $fileDuplicate->addFile($splFileInfo);
                        } else {
                            $fileDuplicateCollection->offsetSet($collectionKey, $fileDuplicate);
                        }

                        break 2;
                    }
                }
            }

            $this->io->progressAdvance();
        }

        //        // Perform a second iteration over all files and now add all files that are not yet included in the list
        //        foreach ($iterator as $splFileInfo) {
        //            $fileFound     = false;
        //            $collectionKey = $this->getPathNameWithoutExtension($splFileInfo);
        //
        //            if ($fileDuplicateCollection->offsetExists($collectionKey)) {
        //                /** @var FileDuplicate $fileDuplicate */
        //                $fileDuplicate = $fileDuplicateCollection->offsetGet($collectionKey);
        //
        //                foreach ($fileDuplicate->getFiles() as $duplicateSplFileInfo) {
        //                    if ($splFileInfo->getPathname() === $duplicateSplFileInfo->getPathname()) {
        //                        $fileFound = true;
        //                        break;
        //                    }
        //                }
        //
        //                if ($fileFound === false) {
        //                    // Add the file to the list of files to be renamed
        //                    $fileDuplicate->addFile($splFileInfo);
        //                }
        //            }
        //        }

        $this->io->progressFinish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Removes the file extension from the pathname.
     *
     * @param SplFileInfo $fileInfo
     *
     * @return string
     */
    private function getPathNameWithoutExtension(SplFileInfo $fileInfo): string
    {
        // Remove the file extension from the pathname
        return substr(
            $fileInfo->getPathname(),
            0,
            -strlen('.' . $fileInfo->getExtension())
        );
    }

    /**
     * Returns the new target filename.
     *
     * @param string      $targetBasename
     * @param SplFileInfo $splFileInfo
     *
     * @return string
     */
    private function getTargetFilename(
        string $targetBasename,
        SplFileInfo $splFileInfo,
    ): string {
        return $targetBasename . '.' . $splFileInfo->getExtension();
    }

    /**
     * Returns the new target filename.
     *
     * @param string      $pattern
     * @param SplFileInfo $splFileInfo
     *
     * @return string|null
     */
    private function getExifDateFormatted(
        string $pattern,
        SplFileInfo $splFileInfo,
    ): ?string {
        // Look up EXIF data in the file list
        $exifData = @exif_read_data($splFileInfo->getPathname());

        //        if ($exifData !== false) {
        //            $this->io->text('Extract EXIF data from: ' . $splFileInfo->getPathname());
        //        }

        // Ignore files without EXIF data
        if ($exifData === false) {
            return null;
        }

        if (!isset($exifData['DateTimeOriginal'])) {
            return null;
        }

        //        $this->io->text('=> Found "DateTimeOriginal" => ' . $exifData['DateTimeOriginal']);

        // Store the date and time the image/video was recorded

        /** @var string $exifDateTimeOriginal */
        $exifDateTimeOriginal = $exifData['DateTimeOriginal'];

        /** @var string $exifSubSecTimeOriginal */
        $exifSubSecTimeOriginal = $exifData['SubSecTimeOriginal'] ?? '';

        try {
            $dateTimeOriginal = new DateTime($exifDateTimeOriginal);

            if ($exifSubSecTimeOriginal !== '') {
                if (strlen($exifSubSecTimeOriginal) > 4) {
                    $dateTimeOriginal->modify('+' . $exifSubSecTimeOriginal . ' Microseconds');
                } else {
                    $dateTimeOriginal->modify('+' . $exifSubSecTimeOriginal . ' Milliseconds');
                }
            }
        } catch (Exception) {
            //            $this->io->warning('=> Invalid EXIF date format in "DateTimeOriginal".');

            return null;
        }

        return $dateTimeOriginal->format($pattern);
    }
}
