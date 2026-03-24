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
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairing;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingServiceInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;

use function array_filter;
use function array_map;
use function array_unique;
use function implode;
use function is_dir;
use function is_string;
use function preg_quote;

/**
 * Renames photos and videos using their EXIF DateTimeOriginal value as the target
 * filename (e.g. "2023-01-15_14-30-00-123.jpg"). Supports Apple Live Photo
 * companion pairing via content identifiers: a second scan pass matches MOV files
 * to their corresponding HEIC/JPG group even when the MOV has no EXIF date.
 * Groups by target basename so files with different extensions but the same
 * capture time share one group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RenameByExifDateCommand extends AbstractRenameCommand
{
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly LivePhotoPairingServiceInterface $livePhotoPairingService,
        private readonly PerceptualHashCalculatorInterface $perceptualHashCalculator,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService);
    }

    /**
     * PHP date() format string defining the target basename pattern.
     */
    private string $targetFilenamePattern = '';

    /**
     * Lazily created EXIF date filename strategy, reset when the pattern changes.
     */
    private ?ExifDateFilenameStrategy $exifDateFilenameStrategy = null;

    /**
     * Lazily created duplicate identifier strategy.
     */
    private ?DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy = null;

    /**
     * Configures the EXIF date rename command with its name, description, and options.
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:exif')
            ->setDescription(
                'Renames files by EXIF date (incl. Apple Live Photos).'
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
     * Sets up the persistent metadata cache before the pipeline and flushes it afterwards.
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

        $this->configureProviderTimezone($this->exifMetadataProvider, $this->input);

        $metadataCache = $this->configureProviderCache($this->exifMetadataProvider);
        $signalCache   = $this->createPerceptualSignalCache();

        if ($this->perceptualHashCalculator instanceof PerceptualHashCalculator) {
            $this->perceptualHashCalculator->setSignalCache($signalCache);
        }

        $result = parent::executeCommand();

        $metadataCache->flush();
        $signalCache->flush();

        return $result;
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     */
    #[Override]
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        $fileExtensionRegex = '/\.(' . implode('|', array_map(
            static fn (string $ext): string => $ext === 'jpg' ? 'jpe?g' : preg_quote($ext, '/'),
            array_unique(array_filter(
                Constants::SUPPORTED_MEDIA_EXTENSIONS,
                static fn (string $ext): bool => $ext !== 'jpeg',
            )),
        )) . ')$/i';

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
     */
    #[Override]
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $fileDuplicateCollection = parent::groupFilesByDuplicateIdentifier($iterator);

        $this->io->newLine();
        $this->io->text('<fg=cyan>Pairing Live Photos</>');

        $fileCount = $this->duplicateDetectionService->getLastScannedFileCount();

        $iterator->rewind();

        /** @var ProgressBar|null $progressBar */
        $progressBar = null;

        if ($fileCount > 0) {
            $progressBar = $this->io->createProgressBar($fileCount);
            $progressBar->setFormat(Constants::PROGRESS_BAR_FORMAT);
            $progressBar->start();
        }

        $pairings = $this->livePhotoPairingService->pairByContentIdentifier(
            iterator: $iterator,
            fileDuplicateCollection: $fileDuplicateCollection,
            contentIdentifierResolver: [$this->getExifDateFilenameStrategy(), 'getLivePhotoContentIdentifier'],
            onFileInspected: function () use ($progressBar): void {
                $progressBar?->advance();
            },
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

        // Release cached metadata — all content identifiers have been captured.
        $this->exifMetadataProvider->clearCache();

        return $fileDuplicateCollection;
    }

    /**
     * Returns the strategy that builds the target filename based on EXIF dates.
     */
    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        return $this->getExifDateFilenameStrategy();
    }

    /**
     * Provides the duplicate identifier strategy capable of handling Live Photos.
     */
    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return $this->duplicateIdentifierStrategy ??= new TargetBasenameStrategy();
    }

    /**
     * Creates the EXIF date rename strategy using the configured filename pattern.
     */
    private function getExifDateFilenameStrategy(): ExifDateFilenameStrategy
    {
        return $this->exifDateFilenameStrategy ??= new ExifDateFilenameStrategy(
            $this->targetFilenamePattern,
            $this->exifMetadataProvider,
        );
    }
}
