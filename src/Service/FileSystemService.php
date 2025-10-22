<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use FilesystemIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

use function basename;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Service for file system operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class FileSystemService implements FileSystemServiceInterface
{
    public const string PROGRESS_BAR_FORMAT = ' %current%/%max% [%bar%] %percent:3s%% | ETA: %estimated:-6s% | Remaining: %remaining:-6s%';

    /**
     * Duplicate identifier pattern.
     */
    public const string DUPLICATE_IDENTIFIER = '-duplicate-';

    /**
     * @var SymfonyStyle
     */
    private readonly SymfonyStyle $io;

    /**
     * Constructor.
     *
     * @param SymfonyStyle $io
     */
    public function __construct(SymfonyStyle $io)
    {
        $this->io = $io;
    }

    /**
     * Creates an iterator for traversing files in the given directory.
     *
     * @param string               $directory         The directory that should be scanned
     * @param RecursiveIterator|null $recursiveIterator Optional preconfigured iterator to use instead of instantiating a default one
     *
     * @return RecursiveIteratorIterator Iterator yielding only leaf nodes (files)
     */
    public function createFileIterator(
        string $directory,
        ?RecursiveIterator $recursiveIterator = null,
    ): RecursiveIteratorIterator {
        if (!($recursiveIterator instanceof RecursiveIterator)) {
            $recursiveIterator = new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            );
        }

        return new RecursiveIteratorIterator(
            $recursiveIterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    /**
     * Counts how many files the provided iterator will yield.
     *
     * @param RecursiveIteratorIterator $iterator Iterator created by {@see createFileIterator()}
     *
     * @return int Number of files encountered while iterating
     */
    public function countFiles(RecursiveIteratorIterator $iterator): int
    {
        $fileCount = 0;

        foreach ($iterator as $ignored) {
            ++$fileCount;
        }

        return $fileCount;
    }

    /**
     * Renames or copies files represented by the provided duplicate collection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Collection describing source/target file pairs grouped by duplicate identifier
     * @param bool                    $dryRun                  When true no filesystem changes are performed
     * @param bool                    $skipDuplicates          Whether files marked as duplicates should be ignored
     * @param bool                    $copyFiles               When true, files are copied instead of moved
     * @param bool                    $listAll                 When true each canonical/original file is printed alongside duplicates
     * @param string|null             $sourceBaseDirectory     Base directory used to render source paths relative to it
     * @param string|null             $targetBaseDirectory     Base directory used to render target paths relative to it
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $dryRun = false,
        bool $skipDuplicates = false,
        bool $copyFiles = false,
        bool $listAll = false,
        ?string $sourceBaseDirectory = null,
        ?string $targetBaseDirectory = null,
    ): void {
        $this->io->text(($copyFiles ? 'Copying' : 'Renaming') . ' files');

        $sourceBaseDirectory = $this->normalizeBaseDirectory($sourceBaseDirectory);
        $targetBaseDirectory = $this->normalizeBaseDirectory($targetBaseDirectory);

        $maxFilenameLength = 0;
        $fileCount         = 0;
        $duplicateCount    = 0;
        $totalOperations   = 0;

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $relativeSource = $this->getRelativePath($rename->getSource(), $sourceBaseDirectory);

                if (strlen($relativeSource) > $maxFilenameLength) {
                    $maxFilenameLength = strlen($relativeSource);
                }

                ++$totalOperations;
            }
        }

        $progressBar = null;

        if ($totalOperations > 0) {
            $progressBar = $this->io->createProgressBar($totalOperations);
            $progressBar->setFormat(self::PROGRESS_BAR_FORMAT);
            $progressBar->start();
        }

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            $canonicalTargetPath = $fileDuplicate->getTarget()->getPathname();

            foreach ($fileDuplicate->getRenames() as $rename) {
                if ($progressBar !== null) {
                    $progressBar->clear();
                }

                $isDuplicateTarget = $rename->getTarget()->getPathname() !== $canonicalTargetPath;
                $isCanonicalEntry  = $listAll
                    && $rename->getSource()->getPathname() === $canonicalTargetPath;

                $status = '[R]';

                if ($isCanonicalEntry) {
                    $status = '[O]';
                } elseif ($isDuplicateTarget) {
                    $status = '[D]';
                }

                $sourcePath = $this->getRelativePath($rename->getSource(), $sourceBaseDirectory);
                $targetPath = $this->getRelativePath($rename->getTarget(), $targetBaseDirectory);

                $this->io->text(
                    sprintf(
                        '%s <fg=yellow>%-' . $maxFilenameLength . 's</> <fg=cyan>→</> <fg=green>%s</>',
                        $status,
                        $sourcePath,
                        $targetPath
                    )
                );

                if ($isDuplicateTarget) {
                    ++$duplicateCount;
                }

                $shouldSkip = $skipDuplicates && $isDuplicateTarget;

                if ($shouldSkip) {
                    if ($progressBar !== null) {
                        $progressBar->clear();
                    }

                    $this->io->text('=> Duplicate! Skip "' . $sourcePath . '"');
                }

                $shouldPerformOperation = $shouldSkip === false && $isCanonicalEntry === false;

                if ($shouldPerformOperation) {
                    ++$fileCount;

                    if ($dryRun === false) {
                        $this->copyOrMoveFile(
                            $rename->getSource(),
                            $rename->getTarget(),
                            $copyFiles
                        );
                    }
                }

                if ($progressBar !== null) {
                    $progressBar->advance();
                    $progressBar->display();
                }
            }
        }

        if ($progressBar !== null) {
            $progressBar->finish();
        }

        $this->io->block($duplicateCount . ' possible duplicates found', 'INFO', 'fg=green');
        $this->io->block($fileCount . ' files renamed', 'INFO', 'fg=green');
    }

    /**
     * Converts a path to be relative to the given base directory when possible.
     */
    private function getRelativePath(SplFileInfo $fileInfo, ?string $baseDirectory): string
    {
        $pathname = $fileInfo->getPathname();

        if ($baseDirectory === null || $baseDirectory === '') {
            return $pathname;
        }

        $normalizedBase = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        if ($normalizedBase === '') {
            return $pathname;
        }

        $prefix = $normalizedBase . DIRECTORY_SEPARATOR;

        if (str_starts_with($pathname, $prefix)) {
            $relativePath = substr($pathname, strlen($prefix));
            $baseName     = basename($normalizedBase);

            if ($baseName === '' || $baseName === DIRECTORY_SEPARATOR) {
                return $relativePath;
            }

            return $baseName . DIRECTORY_SEPARATOR . $relativePath;
        }

        return $pathname;
    }

    /**
     * Normalizes a base directory string for relative path conversion.
     */
    private function normalizeBaseDirectory(?string $baseDirectory): ?string
    {
        if ($baseDirectory === null) {
            return null;
        }

        $normalized = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * Copies or moves a file from source to target.
     *
     * @param SplFileInfo $sourceFileInfo The source file
     * @param SplFileInfo $targetFileInfo The target file
     * @param bool        $copy           Whether to copy the file instead of moving it
     *
     * @throws RuntimeException If the file could not be copied or moved
     */
    protected function copyOrMoveFile(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo, bool $copy = false): void
    {
        $targetDirectory = $targetFileInfo->getPath();

        if (
            !file_exists($targetDirectory)
            && !mkdir($targetDirectory, 0755, true)
            && !is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $targetDirectory
                )
            );
        }

        if (
            $sourceFileInfo->isFile()
            && (!$targetFileInfo->isFile() || $targetFileInfo->isWritable())
        ) {
            if ($copy) {
                // Copies a file from source to target with renaming
                $result = copy($sourceFileInfo->getPathname(), $targetFileInfo->getPathname());

                if ($result === false) {
                    throw new RuntimeException(
                        sprintf(
                            'Failed to copy file to "%s"',
                            $targetFileInfo->getPathname(),
                        ),
                    );
                }
            } else {
                // Moves a file from source to target (removes a file at the source)
                $result = rename($sourceFileInfo->getPathname(), $targetFileInfo->getPathname());

                if ($result === false) {
                    throw new RuntimeException(
                        sprintf(
                            'Failed to move file to "%s"',
                            $targetFileInfo->getPathname(),
                        ),
                    );
                }
            }
        } else {
            throw new RuntimeException(
                sprintf(
                    'Target file "%s" is not writeable',
                    $targetFileInfo->getPathname()
                )
            );
        }
    }
}
