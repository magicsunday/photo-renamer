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
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\LivePhotoContentIdentifierStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;

use function is_string;
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
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        private readonly SafeExifReader $safeExifReader,
        private readonly SafeFileReader $safeFileReader,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService);
    }

    /**
     * @var string
     */
    private string $targetFilenamePattern = '';

    private ?ExifDateFilenameStrategy $exifDateFilenameStrategy = null;

    private ?DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy = null;

    /**
     * Configures the EXIF date rename command with its name, description, and options.
     *
     * @return void
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('exif:date')
            ->setAliases(['rename:exifdate'])
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
     * Executes the command and resets cached strategies when the filename pattern changes.
     *
     * @return int
     */
    #[Override]
    protected function executeCommand(): int
    {
        $this->useFileExtensionFromSource = true;

        $targetFilenamePattern = $this->input->getOption('target-filename-pattern');

        if (is_string($targetFilenamePattern)) {
            $this->targetFilenamePattern = $targetFilenamePattern;
            $this->exifDateFilenameStrategy = null;
            $this->duplicateIdentifierStrategy = null;
        }

        return parent::executeCommand();
    }

    /**
     * Groups files by their duplicate identifier and performs a second pass for Live Photo matches.
     *
     * @param RecursiveIteratorIterator $iterator Iterator with all files that should be processed.
     *
     * @return FileDuplicateCollection
     */
    #[Override]
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $fileDuplicateCollection = parent::groupFilesByDuplicateIdentifier($iterator);

        $this->io->text('Perform a second pass to find all remaining files that share the same base name');
        $this->io->newLine();
        $this->io->progressStart($this->fileSystemService->countFiles($iterator));

        // Perform a second iteration over all files and now add all files that are not yet included in the list

        /** @var SplFileInfo $sourceFileInfo */
        foreach ($iterator as $sourceFileInfo) {
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

                        if ($fileDuplicateCollection->has($duplicateIdentifier)) {
                            /** @var FileDuplicate $fileDuplicate */
                            $fileDuplicate = $fileDuplicateCollection->get($duplicateIdentifier);
                            $fileDuplicate->addFile($sourceFileInfo);
                        } else {
                            $fileDuplicateCollection->set($duplicateIdentifier, $fileDuplicate);
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

    /**
     * Returns the strategy that builds the target filename based on EXIF dates.
     *
     * @return RenameStrategyInterface
     */
    #[Override]
    protected function getTargetFilenameProcessor(): RenameStrategyInterface
    {
        return $this->getExifDateFilenameStrategy();
    }

    /**
     * Provides the duplicate identifier strategy capable of handling Live Photos.
     *
     * @return DuplicateIdentifierStrategyInterface
     */
    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        if ($this->duplicateIdentifierStrategy === null) {
            $this->duplicateIdentifierStrategy = new LivePhotoContentIdentifierStrategy(
                $this->getExifDateFilenameStrategy(),
            );
        }

        return $this->duplicateIdentifierStrategy;
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
     * Creates the EXIF date rename strategy using the configured filename pattern.
     *
     * @return ExifDateFilenameStrategy
     */
    private function getExifDateFilenameStrategy(): ExifDateFilenameStrategy
    {
        if ($this->exifDateFilenameStrategy === null) {
            $this->exifDateFilenameStrategy = new ExifDateFilenameStrategy(
                $this->targetFilenamePattern,
                $this->safeExifReader,
                $this->safeFileReader,
            );
        }

        return $this->exifDateFilenameStrategy;
    }
}
