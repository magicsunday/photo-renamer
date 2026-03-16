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
use MagicSunday\Renamer\Command\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\LivePhotoPairingService;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\LivePhotoContentIdentifierStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifMetadataProvider;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;

use function is_dir;
use function is_string;

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
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly LivePhotoPairingService $livePhotoPairingService,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService);
    }

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
            $this->targetFilenamePattern       = $targetFilenamePattern;
            $this->exifDateFilenameStrategy    = null;
            $this->duplicateIdentifierStrategy = null;
        }

        return parent::executeCommand();
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     */
    #[Override]
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        $fileExtensionRegex = '/\.(jpe?g|heic|mov|mp4)$/i';

        $recursiveIterator = null;

        if (is_dir($this->sourceDirectory)) {
            $recursiveIterator = new RecursiveRegexFileFilterIterator(
                new RecursiveDirectoryIterator(
                    $this->sourceDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                $fileExtensionRegex
            );
        }

        return $this->fileSystemService
            ->createFileIterator(
                $this->sourceDirectory,
                $recursiveIterator
            );
    }

    /**
     * Groups files by their duplicate identifier and performs a second pass for Live Photo matches.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator iterator with all files that should be processed
     *
     * @return FileDuplicateCollection
     */
    #[Override]
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $fileDuplicateCollection = parent::groupFilesByDuplicateIdentifier($iterator);

        $this->io->text('Perform a fallback pass to find remaining files that share the same base name');
        $this->io->newLine();

        $fileCount = $this->fileSystemService->countFiles($iterator);

        $iterator->rewind();

        /** @var ProgressBar|null $progressBar */
        $progressBar = null;

        if ($fileCount > 0) {
            $progressBar = $this->io->createProgressBar($fileCount);
            $progressBar->setFormat(FileSystemService::PROGRESS_BAR_FORMAT);
            $progressBar->start();
        }

        $pairings = $this->livePhotoPairingService->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $fileDuplicateCollection,
            contentIdentifierResolver: [$this->getExifDateFilenameStrategy(), 'getLivePhotoContentIdentifier'],
            onFileInspected: function () use ($progressBar): void {
                $progressBar?->advance();
            },
            matchByContentIdentifier: true,
        );

        /** @var LivePhotoPairing $pairing */
        foreach ($pairings as $pairing) {
            $duplicateIdentifier = $pairing->getDuplicateIdentifier();

            $fileDuplicate = $fileDuplicateCollection->get($duplicateIdentifier);

            if ($fileDuplicate instanceof FileDuplicate) {
                $fileDuplicate->addFile($pairing->getSourceFile());

                continue;
            }

            $fileDuplicate = new FileDuplicate()
                ->addFile($pairing->getSourceFile())
                ->setTarget($pairing->getTargetFile());

            $fileDuplicateCollection->set($duplicateIdentifier, $fileDuplicate);
        }

        $progressBar?->finish();
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
        if (!$this->duplicateIdentifierStrategy instanceof DuplicateIdentifierStrategyInterface) {
            $this->duplicateIdentifierStrategy = new LivePhotoContentIdentifierStrategy(
                $this->getExifDateFilenameStrategy(),
            );
        }

        return $this->duplicateIdentifierStrategy;
    }

    /**
     * Creates the EXIF date rename strategy using the configured filename pattern.
     *
     * @return ExifDateFilenameStrategy
     */
    private function getExifDateFilenameStrategy(): ExifDateFilenameStrategy
    {
        if (!$this->exifDateFilenameStrategy instanceof ExifDateFilenameStrategy) {
            $this->exifDateFilenameStrategy = new ExifDateFilenameStrategy(
                $this->targetFilenamePattern,
                $this->exifMetadataProvider,
            );
        }

        return $this->exifDateFilenameStrategy;
    }
}
