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
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use Override;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;

use function strlen;

/**
 * Recursively renames all files using the EXIF attribute "DateTimeOriginal".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByExifDateCommand extends AbstractRenameCommand
{
    /**
     * @var string
     */
    private string $targetFilenamePattern = '';

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

    #[Override]
    protected function executeCommand(): int
    {
        $this->useFileExtensionFromSource = true;
        $this->targetFilenamePattern      = $this->input->getOption('target-filename-pattern');

        return parent::executeCommand();
    }

    #[Override]
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $fileDuplicateCollection = parent::groupFilesByDuplicateIdentifier($iterator);

        $this->io->text('Perform a second pass to find all remaining files that share the same base name');
        $this->io->newLine();
        $this->io->progressStart($this->countFiles($iterator));

        // Perform a second iteration over all files and now add all files that are not yet included in the list
        foreach ($iterator as $sourceFileInfo) {
            // $this->io->text($sourceFileInfo->getPathname());
            foreach ($fileDuplicateCollection as $duplicateIdentifier => $fileDuplicate) {
                foreach ($fileDuplicate->getFiles() as $duplicateSplFileInfo) {
                    if ($sourceFileInfo->getPathname() === $duplicateSplFileInfo->getPathname()) {
                        break 2;
                    }

                    $sourceWithoutExtension = $this->getPathNameWithoutExtension($sourceFileInfo);
                    $targetWithoutExtension = $this->getPathNameWithoutExtension($duplicateSplFileInfo);

                    if ($sourceWithoutExtension === $targetWithoutExtension) {
                        $targetFileInfo = new SplFileInfo(
                            $sourceFileInfo->getPath()
                            . DIRECTORY_SEPARATOR
                            . $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension())
                            . '.'
                            . $sourceFileInfo->getExtension(),
                        );

                        // Create duplicate object storing relevant data
                        $fileDuplicate = new FileDuplicate();
                        $fileDuplicate
                            ->addFile($sourceFileInfo)
                            ->setTarget($targetFileInfo);

                        $duplicateIdentifier = substr($duplicateIdentifier, 0, -strlen('.' . $sourceFileInfo->getExtension()))
                            . '.' . $targetFileInfo->getExtension();

                        if ($fileDuplicateCollection->offsetExists($duplicateIdentifier)) {
                            /** @var FileDuplicate $fileDuplicate */
                            $fileDuplicate = $fileDuplicateCollection->offsetGet($duplicateIdentifier);
                            $fileDuplicate->addFile($sourceFileInfo);
                        } else {
                            $fileDuplicateCollection->offsetSet($duplicateIdentifier, $fileDuplicate);
                        }

                        break 2;
                    }
                }
            }

            $this->io->progressAdvance();
        }

        //        // Perform a second iteration over all files and now add all files that are not yet included in the list
        //        foreach ($iterator as $sourceFileInfo) {
        //            $fileFound     = false;
        //            $duplicateIdentifier = $this->getPathNameWithoutExtension($sourceFileInfo);
        //
        //            if ($fileDuplicateCollection->offsetExists($duplicateIdentifier)) {
        //                /** @var FileDuplicate $fileDuplicate */
        //                $fileDuplicate = $fileDuplicateCollection->offsetGet($duplicateIdentifier);
        //
        //                foreach ($fileDuplicate->getFiles() as $duplicateSplFileInfo) {
        //                    if ($sourceFileInfo->getPathname() === $duplicateSplFileInfo->getPathname()) {
        //                        $fileFound = true;
        //                        break;
        //                    }
        //                }
        //
        //                if ($fileFound === false) {
        //                    // Add the file to the list of files to be renamed
        //                    $fileDuplicate->addFile($sourceFileInfo);
        //                }
        //            }
        //        }

        $this->io->progressFinish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    #[Override]
    protected function getTargetFilename(SplFileInfo $sourceFileInfo): ?string
    {
        // Create a new filename based on the formatted value of the EXIF field "DateTimeOriginal".
        $targetBasename = $this->getExifDateFormatted($this->targetFilenamePattern, $sourceFileInfo);

        if ($targetBasename === null) {
            return null;
        }

        return $targetBasename . '.' . $sourceFileInfo->getExtension();
    }

    #[Override]
    protected function getUniqueDuplicateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        // We want to find duplicates across all directories based
        // on the EXIF field "DateTimeOriginal" of the image.
        return $targetFileInfo->getFilename();
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
     * Returns the formatted EXIF date of the specified file, formatted according to the specified pattern.
     *
     * @param string      $pattern
     * @param SplFileInfo $sourceFileInfo
     *
     * @return string|null
     */
    private function getExifDateFormatted(
        string $pattern,
        SplFileInfo $sourceFileInfo,
    ): ?string {
        // Look up EXIF data
        $exifData = @exif_read_data($sourceFileInfo->getPathname());

        //        if ($exifData !== false) {
        //            $this->io->text('Extract EXIF data from: ' . $sourceFileInfo->getPathname());
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
