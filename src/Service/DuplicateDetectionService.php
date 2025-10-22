<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function sprintf;
use function strtolower;

/**
 * Service for duplicate detection operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class DuplicateDetectionService implements DuplicateDetectionServiceInterface
{
    private const string LIVE_PHOTO_IDENTIFIER_PREFIX = 'live-photo:';

    /**
     * Extensions that identify still image assets within Live Photo groups.
     *
     * @var array<int, string>
     */
    private const array LIVE_PHOTO_STILL_EXTENSIONS = ['heic', 'heif', 'jpg', 'jpeg'];

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
     * @var bool
     */
    private bool $listAll = false;

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
     * Defines the directory that will be scanned for potential duplicates.
     *
     * @param string $sourceDirectory Absolute path to the directory being analysed.
     *
     * @return DuplicateDetectionService Fluent reference for method chaining.
     */
    public function setSourceDirectory(string $sourceDirectory): DuplicateDetectionService
    {
        $this->sourceDirectory = $sourceDirectory;

        return $this;
    }

    /**
     * Sets the directory in which renamed or copied files should be placed.
     *
     * @param string $targetDirectory Absolute path to the destination directory.
     *
     * @return DuplicateDetectionService Fluent reference for method chaining.
     */
    public function setTargetDirectory(string $targetDirectory): DuplicateDetectionService
    {
        $this->targetDirectory = $targetDirectory;

        return $this;
    }

    /**
     * Controls whether the original source file extension should be preserved for duplicates.
     *
     * @param bool $useFileExtensionFromSource When true the source file extension is retained in duplicates.
     *
     * @return DuplicateDetectionService Fluent reference for method chaining.
     */
    public function setUseFileExtensionFromSource(bool $useFileExtensionFromSource): DuplicateDetectionService
    {
        $this->useFileExtensionFromSource = $useFileExtensionFromSource;

        return $this;
    }

    /**
     * @param bool $listAll
     *
     * @return DuplicateDetectionService
     */
    public function setListAll(bool $listAll): DuplicateDetectionService
    {
        $this->listAll = $listAll;

        return $this;
    }

    /**
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @param RecursiveIteratorIterator            $iterator                     Iterator yielding candidate files.
     * @param RenameStrategyInterface              $renameStrategy               Strategy used to generate target filenames.
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy Strategy that identifies duplicate groups.
     *
     * @return FileDuplicateCollection Collection describing discovered duplicate groups.
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
    ): FileDuplicateCollection {
        $progressBar = $this->startProgressBar(
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

            try {
                $duplicateIdentifier = $duplicateIdentifierStrategy->generateIdentifier(
                    $sourceFileInfo,
                    $targetFileInfo
                );
            } catch (HashComputationException $exception) {
                $this->io->error($exception->getMessage());

                $progressBar->advance();

                continue;
            }

            if ($duplicateIdentifier === false) {
                continue;
            }

            // Create duplicate object storing relevant data
            $fileDuplicate = new FileDuplicate();
            $fileDuplicate
                ->addFile($sourceFileInfo)
                ->setTarget($targetFileInfo);

            if ($fileDuplicateCollection->has($duplicateIdentifier)) {
                /** @var FileDuplicate $fileDuplicate */
                $fileDuplicate = $fileDuplicateCollection->get($duplicateIdentifier);

                $this->promoteLivePhotoTargetIfNecessary(
                    $duplicateIdentifier,
                    $fileDuplicate,
                    $targetFileInfo,
                );

                $fileDuplicate->addFile($sourceFileInfo);
            } else {
                $fileDuplicateCollection->set($duplicateIdentifier, $fileDuplicate);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Creates consecutive filenames for duplicate files in the supplied collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection whose entries should receive duplicate filenames.
     *
     * @return FileDuplicateCollection Updated collection with rename operations populated.
     */
    public function createDuplicateFilenames(FileDuplicateCollection $fileDuplicateCollection): FileDuplicateCollection
    {
        $progressBar = $this->startProgressBar($fileDuplicateCollection->count());

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

            /** @var RenameList $renames */
            $renames = $fileDuplicate->getRenames();

            $canonicalTargetPath = $fileDuplicate->getTarget()->getPathname();
            /** @var Rename|null $canonicalRename */
            $canonicalRename = null;

            foreach ($renames as $rename) {
                if ($rename->getTarget()->getPathname() === $canonicalTargetPath) {
                    $canonicalRename = $rename;
                    break;
                }
            }

            $renames->reindex();
            $fileDuplicate->setRenames($renames);

            $duplicateCount = 1;
            $duplicateEntries = 0;

            foreach ($fileDuplicate->getRenames() as $rename) {
                if ($canonicalRename instanceof Rename && $rename === $canonicalRename) {
                    continue;
                }

                ++$duplicateEntries;
            }

            $hasAdditionalRenames = $duplicateEntries > 1;
            $processedDuplicates = 0;

            // Check if the target file already exists in the file system, so we need to adjust
            // the new target name again.
            foreach ($fileDuplicate->getRenames() as $rename) {
                $isCanonicalRename = $canonicalRename instanceof Rename && $rename === $canonicalRename;
                $requiresCanonicalDisambiguation = ($canonicalRename instanceof Rename && $rename !== $canonicalRename)
                    && $rename->getTarget()->getPathname() === $canonicalTargetPath;

                $rename->setTarget(
                    $this->createDuplicateTargetFileInfo(
                        $rename->getSource(),
                        $rename->getTarget(),
                        $duplicateCount,
                        $processedDuplicates === 0,
                        $hasAdditionalRenames,
                        $requiresCanonicalDisambiguation,
                        $isCanonicalRename,
                    )
                );

                ++$processedDuplicates;
            }

            if ($canonicalRename instanceof Rename) {
                $fileDuplicate->setTarget($canonicalRename->getTarget());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Creates and starts a Symfony progress bar tailored to the current workload.
     *
     * @param int $max Maximum number of steps the progress bar should represent.
     *
     * @return ProgressBar Configured progress bar instance ready for updates.
     */
    private function startProgressBar(int $max): ProgressBar
    {
        $progressBar = $this->io->createProgressBar($max);
        $progressBar->setFormat(FileSystemService::PROGRESS_BAR_FORMAT);
        $progressBar->start();

        return $progressBar;
    }

    /**
     * Resolves the target file information for a duplicate, ensuring unique filenames.
     *
     * @param SplFileInfo $source         Source file currently being processed.
     * @param SplFileInfo $target         Initial target file information.
     * @param int         $duplicateCount Counter used to create unique duplicate suffixes (passed by reference).
     * @param bool        $isFirst        Whether the file is the first item within the duplicate group.
     *
     * @return SplFileInfo File information pointing to the deduplicated target.
     */
    private function createDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        bool $isFirst = false,
        bool $hasAdditionalRenames = false,
        bool $requiresCanonicalDisambiguation = false,
        bool $isCanonicalRename = false,
    ): SplFileInfo {
        $duplicateBasename = $target->getBasename('.' . $target->getExtension());

        $allowSourceTargetMatch = $isCanonicalRename ? true : !$hasAdditionalRenames;

        if ($target->isFile()) {
            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount,
                $requiresCanonicalDisambiguation,
                $allowSourceTargetMatch,
            );
        }

        if (!$isFirst) {
            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount,
                $hasAdditionalRenames || $requiresCanonicalDisambiguation,
                $allowSourceTargetMatch,
            );
        }

        if ($hasAdditionalRenames || $requiresCanonicalDisambiguation) {
            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount,
                $requiresCanonicalDisambiguation,
                $allowSourceTargetMatch,
            );
        }

        return $target;
    }

    /**
     * Generates a new target file info instance whose path does not yet exist on disk.
     *
     * @param SplFileInfo $source         Source file currently being processed.
     * @param SplFileInfo $target         Initial target file information.
     * @param string      $targetBasename Base filename (without extension) used for duplicate naming.
     * @param int         $duplicateCount Counter used to create unique duplicate suffixes (passed by reference).
     *
     * @return SplFileInfo Newly generated file info pointing to a non-existing file.
     */
    private function getNewUniqueDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
        bool $forceDuplicateSuffix = false,
        bool $allowSourceTargetMatch = false,
    ): SplFileInfo {
        $duplicateFileInfo = $target;

        if ($allowSourceTargetMatch && $duplicateFileInfo->getPathname() === $source->getPathname()) {
            return $duplicateFileInfo;
        }

        if ($forceDuplicateSuffix) {
            $duplicateFileInfo = $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $targetBasename,
                $duplicateCount
            );

            ++$duplicateCount;

            if ($allowSourceTargetMatch && $duplicateFileInfo->getPathname() === $source->getPathname()) {
                return $duplicateFileInfo;
            }
        }

        while ($duplicateFileInfo->isFile() || (!$allowSourceTargetMatch && $duplicateFileInfo->getPathname() === $source->getPathname())) {
            if ($allowSourceTargetMatch && $duplicateFileInfo->getPathname() === $source->getPathname()) {
                break;
            }

            $duplicateFileInfo = $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $targetBasename,
                $duplicateCount
            );

            ++$duplicateCount;

            if ($allowSourceTargetMatch && $duplicateFileInfo->getPathname() === $source->getPathname()) {
                break;
            }
        }

        return $duplicateFileInfo;
    }

    /**
     * Returns a new file info object with a unique filename.
     *
     * @param SplFileInfo $source         Source file currently being processed.
     * @param SplFileInfo $target         Initial target file information.
     * @param string      $targetBasename Base filename (without extension) used for duplicate naming.
     * @param int         $duplicateCount Counter used to create unique duplicate suffixes (passed by reference).
     *
     * @return SplFileInfo File info representing the next duplicate candidate.
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

        return new SplFileInfo($targetPathname);
    }

    /**
     * Builds the target pathname for a source file and generated filename.
     *
     * @param SplFileInfo $sourceFileInfo Source file for which the target path should be computed.
     * @param string      $targetFilename Filename (without directory) to use in the destination.
     *
     * @return string Absolute pathname pointing to the intended target location.
     */
    public function getTargetPathname(SplFileInfo $sourceFileInfo, string $targetFilename): string
    {
        $sourcePath = $sourceFileInfo->getPath();
        $relativePath = $sourcePath;

        if (str_starts_with($sourcePath, $this->sourceDirectory)) {
            $relativePath = substr($sourcePath, strlen($this->sourceDirectory));
        }

        $relativePath = trim((string) $relativePath, DIRECTORY_SEPARATOR);

        $targetPath = rtrim($this->targetDirectory, DIRECTORY_SEPARATOR);

        if ($relativePath !== '') {
            $targetPath .= DIRECTORY_SEPARATOR . $relativePath;
        }

        return $targetPath . DIRECTORY_SEPARATOR . $targetFilename;
    }

    /**
     * Returns a new target file object for the given source file object.
     *
     * @param SplFileInfo             $sourceFileInfo Source file that should be renamed.
     * @param RenameStrategyInterface $renameStrategy Strategy responsible for generating the target filename.
     *
     * @return SplFileInfo|null Target file info when the strategy yields a filename, otherwise null.
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

    private function promoteLivePhotoTargetIfNecessary(
        string $duplicateIdentifier,
        FileDuplicate $fileDuplicate,
        SplFileInfo $candidateTarget,
    ): void {
        if (!str_starts_with($duplicateIdentifier, self::LIVE_PHOTO_IDENTIFIER_PREFIX)) {
            return;
        }

        if (!$this->isLivePhotoStill($candidateTarget)) {
            return;
        }

        if ($this->isLivePhotoStill($fileDuplicate->getTarget())) {
            return;
        }

        $fileDuplicate->setTarget($candidateTarget);
    }

    private function isLivePhotoStill(SplFileInfo $fileInfo): bool
    {
        $extension = strtolower($fileInfo->getExtension());

        if ($extension === '') {
            return false;
        }

        return in_array($extension, self::LIVE_PHOTO_STILL_EXTENSIONS, true);
    }
}
