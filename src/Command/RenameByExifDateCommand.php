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
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\TargetFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;

use Throwable;
use function function_exists;
use function is_array;
use function is_scalar;
use function is_string;
use function strlen;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;

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

        $targetFilenamePattern = $this->input->getOption('target-filename-pattern');

        if (is_string($targetFilenamePattern)) {
            $this->targetFilenamePattern = $targetFilenamePattern;
        }

        return parent::executeCommand();
    }

    #[Override]
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $fileDuplicateCollection = parent::groupFilesByDuplicateIdentifier($iterator);

        $this->io->text('Perform a second pass to find all remaining files that share the same base name');
        $this->io->newLine();
        $this->io->progressStart($this->fileSystemService->countFiles($iterator));

        $identifierLookup = [];
        $processedFiles   = [];

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            $targetBasename = $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension());

            foreach ($fileDuplicate->getFiles() as $duplicateSplFileInfo) {
                $processedFiles[$duplicateSplFileInfo->getPathname()] = true;

                $contentIdentifier = $this->getLivePhotoContentIdentifier($duplicateSplFileInfo);

                if ($contentIdentifier !== null && !isset($identifierLookup[$contentIdentifier])) {
                    $identifierLookup[$contentIdentifier] = [
                        'targetBasename' => $targetBasename,
                    ];
                }
            }
        }

        // Perform a second iteration over all files and now add all files that are not yet included in the list

        /** @var SplFileInfo $sourceFileInfo */
        foreach ($iterator as $sourceFileInfo) {
            $sourcePathname = $sourceFileInfo->getPathname();

            if (isset($processedFiles[$sourcePathname])) {
                $this->io->progressAdvance();

                continue;
            }

            $fileMatched         = false;
            $contentIdentifier   = $this->getLivePhotoContentIdentifier($sourceFileInfo);
            $sourceFileExtension = $sourceFileInfo->getExtension();

            if ($contentIdentifier !== null && isset($identifierLookup[$contentIdentifier])) {
                $fileMatched = true;

                $lookupEntry    = $identifierLookup[$contentIdentifier];
                $targetBasename = $lookupEntry['targetBasename'];

                $targetFileInfo = new SplFileInfo(
                    $sourceFileInfo->getPath()
                    . DIRECTORY_SEPARATOR
                    . $targetBasename
                    . '.'
                    . $sourceFileExtension,
                );

                $duplicateIdentifier = $targetBasename . '.' . $sourceFileExtension;

                if ($fileDuplicateCollection->offsetExists($duplicateIdentifier)) {
                    /** @var FileDuplicate $duplicate */
                    $duplicate = $fileDuplicateCollection->offsetGet($duplicateIdentifier);
                    $duplicate->addFile($sourceFileInfo);
                } else {
                    $duplicate = new FileDuplicate();
                    $duplicate
                        ->addFile($sourceFileInfo)
                        ->setTarget($targetFileInfo);

                    $fileDuplicateCollection->offsetSet($duplicateIdentifier, $duplicate);
                }

                $processedFiles[$sourcePathname] = true;
            }

            if ($fileMatched === false) {
                $sourceWithoutExtension = $this->getPathNameWithoutExtension($sourceFileInfo);

                foreach ($fileDuplicateCollection as $fileDuplicate) {
                    foreach ($fileDuplicate->getFiles() as $duplicateSplFileInfo) {
                        if ($sourceWithoutExtension !== $this->getPathNameWithoutExtension($duplicateSplFileInfo)) {
                            continue;
                        }

                        $fileMatched     = true;
                        $targetBasename  = $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension());
                        $targetFileInfo  = new SplFileInfo(
                            $sourceFileInfo->getPath()
                            . DIRECTORY_SEPARATOR
                            . $targetBasename
                            . '.'
                            . $sourceFileExtension,
                        );
                        $newIdentifier   = $targetBasename . '.' . $sourceFileExtension;

                        if ($fileDuplicateCollection->offsetExists($newIdentifier)) {
                            /** @var FileDuplicate $duplicate */
                            $duplicate = $fileDuplicateCollection->offsetGet($newIdentifier);
                            $duplicate->addFile($sourceFileInfo);
                        } else {
                            $duplicate = new FileDuplicate();
                            $duplicate
                                ->addFile($sourceFileInfo)
                                ->setTarget($targetFileInfo);

                            $fileDuplicateCollection->offsetSet($newIdentifier, $duplicate);
                        }

                        $processedFiles[$sourcePathname] = true;

                        break 2;
                    }
                }
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    #[Override]
    protected function getTargetFilenameProcessor(): RenameStrategyInterface
    {
        return new ExifDateFilenameStrategy($this->targetFilenamePattern);
    }

    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        // We want to find duplicates across all directories based
        // on the EXIF field "DateTimeOriginal" of the image.
        return new TargetFilenameStrategy();
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
     * Returns the live photo content identifier from supported metadata blocks, if available.
     *
     * @param SplFileInfo $fileInfo
     *
     * @return string|null
     */
    private function getLivePhotoContentIdentifier(SplFileInfo $fileInfo): ?string
    {
        if (function_exists('exif_read_data') === false) {
            return null;
        }

        try {
            $metadata = @exif_read_data($fileInfo->getPathname(), null, true, false);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($metadata)) {
            return null;
        }

        foreach ($metadata as $section) {
            if (!is_array($section)) {
                continue;
            }

            foreach ($section as $key => $value) {
                $normalizedKey = strtolower((string) $key);
                $normalizedKey = str_replace(['_', '-', '.', ':'], '', $normalizedKey);

                if (!str_contains($normalizedKey, 'contentidentifier')) {
                    continue;
                }

                $scalarValue = $this->extractScalarMetadataValue($value);

                if ($scalarValue !== null) {
                    return $scalarValue;
                }
            }
        }

        return null;
    }

    /**
     * Extracts the first scalar value from nested metadata value structures.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function extractScalarMetadataValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            $scalarValue = trim((string) $value);

            return $scalarValue === '' ? null : $scalarValue;
        }

        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $scalarValue = $this->extractScalarMetadataValue($nestedValue);

                if ($scalarValue !== null) {
                    return $scalarValue;
                }
            }
        }

        return null;
    }
}
