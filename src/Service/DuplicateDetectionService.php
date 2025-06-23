<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Service for duplicate detection operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class DuplicateDetectionService implements DuplicateDetectionServiceInterface
{
    /**
     * @var FileSystemService
     */
    private readonly FileSystemService $fileSystemService;

    /**
     * @var SymfonyStyle
     */
    private readonly SymfonyStyle $io;

    /**
     * @var string
     */
    private string $sourceDirectory;

    /**
     * @var string
     */
    private string $targetDirectory;

    /**
     * @var bool
     */
    private bool $useFileExtensionFromSource = false;

    /**
     * Constructor.
     *
     * @param FileSystemService $fileSystemService
     * @param SymfonyStyle      $io
     */
    public function __construct(
        FileSystemService $fileSystemService,
        SymfonyStyle $io,
    ) {
        $this->fileSystemService = $fileSystemService;
        $this->io                = $io;
    }

    /**
     * @param string $sourceDirectory
     *
     * @return DuplicateDetectionService
     */
    public function setSourceDirectory(string $sourceDirectory): DuplicateDetectionService
    {
        $this->sourceDirectory = $sourceDirectory;

        return $this;
    }

    /**
     * @param string $targetDirectory
     *
     * @return DuplicateDetectionService
     */
    public function setTargetDirectory(string $targetDirectory): DuplicateDetectionService
    {
        $this->targetDirectory = $targetDirectory;

        return $this;
    }

    /**
     * @param bool $useFileExtensionFromSource
     *
     * @return DuplicateDetectionService
     */
    public function setUseFileExtensionFromSource(bool $useFileExtensionFromSource): DuplicateDetectionService
    {
        $this->useFileExtensionFromSource = $useFileExtensionFromSource;

        return $this;
    }

    /**
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @param RecursiveIteratorIterator            $iterator
     * @param RenameStrategyInterface              $renameStrategy
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy
     *
     * @return FileDuplicateCollection
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
    ): FileDuplicateCollection {
        $this->io->progressStart(
            $this->fileSystemService->countFiles($iterator)
        );

        $fileDuplicateCollection = new FileDuplicateCollection();

        /** @var SplFileInfo $sourceFileInfo */
        foreach ($iterator as $sourceFileInfo) {
            // The resulting file object
            $targetFileInfo = $this->getTargetFileInfo(
                $sourceFileInfo,
                $renameStrategy
            );

            if (!($targetFileInfo instanceof SplFileInfo)) {
                continue;
            }

            $duplicateIdentifier = $duplicateIdentifierStrategy->generateIdentifier(
                $sourceFileInfo,
                $targetFileInfo
            );

            if ($duplicateIdentifier === false) {
                continue;
            }

            // Create duplicate object storing relevant data
            $fileDuplicate = new FileDuplicate();
            $fileDuplicate
                ->addFile($sourceFileInfo)
                ->setTarget($targetFileInfo);

            if ($fileDuplicateCollection->offsetExists($duplicateIdentifier)) {
                /** @var FileDuplicate $fileDuplicate */
                $fileDuplicate = $fileDuplicateCollection->offsetGet($duplicateIdentifier);
                $fileDuplicate->addFile($sourceFileInfo);
            } else {
                $fileDuplicateCollection->offsetSet($duplicateIdentifier, $fileDuplicate);
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
        $this->io->newLine();

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
    public function createDuplicateFilenames(FileDuplicateCollection $fileDuplicateCollection): FileDuplicateCollection
    {
        $this->io->progressStart($fileDuplicateCollection->count());

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getFiles() as $renameSourceFileInfo) {
                $renameTargetFileExtension = $fileDuplicate->getTarget()->getExtension();

                // Modify the target file extension if the file extension from the source should be used.
                // This allows us to rename different file types but with the same name.
                if ($this->useFileExtensionFromSource) {
                    $renameTargetFileExtension = $renameSourceFileInfo->getExtension();
                }

                $targetPathname = $this->getTargetPathname(
                    $renameSourceFileInfo,
                    $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension())
                    . '.' . $renameTargetFileExtension
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

            // Remove elements where the source already equals the target (these don't need to be copied or moved)
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
        bool $isFirst = false,
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
            '%s' . FileSystemService::DUPLICATE_IDENTIFIER . '%003d',
            $targetBasename,
            $duplicateCount
        );

        $targetPathname = $this->getTargetPathname(
            $source,
            $newTargetBasename . '.' . $target->getExtension()
        );

        ++$duplicateCount;

        return new SplFileInfo($targetPathname);
    }

    /**
     * Returns, for the given file object and file name, the name and path of the
     * file in the new destination directory.
     *
     * @param SplFileInfo $sourceFileInfo
     * @param string      $targetFilename
     *
     * @return string
     */
    public function getTargetPathname(SplFileInfo $sourceFileInfo, string $targetFilename): string
    {
        $targetPathname = $this->targetDirectory . DIRECTORY_SEPARATOR
            . trim(
                // Remove the source directory part from the current file path
                str_replace(
                    $this->sourceDirectory,
                    '',
                    $sourceFileInfo->getPath()
                ),
                DIRECTORY_SEPARATOR
            );

        return rtrim($targetPathname, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetFilename;
    }

    /**
     * Returns a new target file object for the given source file object.
     *
     * @param SplFileInfo             $sourceFileInfo
     * @param RenameStrategyInterface $renameStrategy
     *
     * @return SplFileInfo|null
     */
    protected function getTargetFileInfo(SplFileInfo $sourceFileInfo, RenameStrategyInterface $renameStrategy): ?SplFileInfo
    {
        try {
            $targetFilename = $renameStrategy->generateFilename($sourceFileInfo);

            if ($targetFilename !== null) {
                // Create a new target file object
                return new SplFileInfo(
                    $this->getTargetPathname(
                        $sourceFileInfo,
                        $targetFilename
                    )
                );
            }
        } catch (TargetFilenameException $exception) {
            $this->io->error($exception->getMessage());
        }

        return null;
    }
}
