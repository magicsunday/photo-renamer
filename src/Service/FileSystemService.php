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

use function sprintf;
use function strlen;
use function str_contains;

/**
 * Service for file system operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class FileSystemService implements FileSystemServiceInterface
{
    private const string PROGRESS_BAR_FORMAT = ' %current%/%max% [%bar%] %percent:3s%% ETA %estimated:-6s%';

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
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $dryRun = false,
        bool $skipDuplicates = false,
        bool $copyFiles = false,
        bool $listAll = false,
    ): void {
        $this->io->text(($copyFiles ? 'Copying' : 'Renaming') . ' files');
        $this->io->newLine();

        $maxFilenameLength = 0;
        $fileCount         = 0;
        $duplicateCount    = 0;
        $totalOperations   = 0;

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                if (strlen($rename->getSource()->getPathname()) > $maxFilenameLength) {
                    $maxFilenameLength = strlen($rename->getSource()->getPathname());
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
            foreach ($fileDuplicate->getRenames() as $rename) {
                if ($progressBar !== null) {
                    $progressBar->clear();
                }

                $isDuplicateTarget = str_contains($rename->getTarget()->getFilename(), self::DUPLICATE_IDENTIFIER);
                $isCanonicalEntry  = $listAll
                    && $rename->getSource()->getPathname() === $fileDuplicate->getTarget()->getPathname();

                $status = '[R]';

                if ($isCanonicalEntry) {
                    $status = '[O]';
                } elseif ($isDuplicateTarget) {
                    $status = '[D]';
                }

                $this->io->text(
                    sprintf(
                        '%s <fg=yellow>%-' . $maxFilenameLength . 's</> <fg=cyan>→</> <fg=green>%s</>',
                        $status,
                        $rename->getSource()->getPathname(),
                        $rename->getTarget()->getPathname()
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

                    $this->io->text('=> Duplicate! Skip "' . $rename->getSource()->getPathname() . '"');
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
            $this->io->newLine();
        }

        $this->io->block($duplicateCount . ' possible duplicates found', 'INFO', 'fg=green');
        $this->io->block($fileCount . ' files renamed', 'INFO', 'fg=green');
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
