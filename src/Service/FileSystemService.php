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
use MagicSunday\Renamer\Model\RenameOptions;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function basename;
use function is_string;
use function preg_match;
use function preg_quote;
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

    private const int MAX_DUPLICATE_SUFFIX = 9999;

    /**
     * Constructor.
     */
    public function __construct(private readonly SymfonyStyle $io)
    {
    }

    /**
     * Creates an iterator for traversing files in the given directory.
     *
     * @param string                                      $directory         The directory that should be scanned
     * @param RecursiveIterator<string, SplFileInfo>|null $recursiveIterator Optional preconfigured iterator to use instead of instantiating a default one
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> Iterator yielding only leaf nodes (files)
     */
    public function createFileIterator(
        string $directory,
        ?RecursiveIterator $recursiveIterator = null,
    ): RecursiveIteratorIterator {
        if (!$recursiveIterator instanceof RecursiveIterator) {
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
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator Iterator created by {@see createFileIterator()}
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
     * @param RenameOptions           $options                 Options controlling the rename operation
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
    ): void {
        $sourceBaseDirectory = $this->normalizeBaseDirectory($options->sourceBaseDirectory);
        $targetBaseDirectory = $this->normalizeBaseDirectory($options->targetBaseDirectory);

        $maxFilenameLength = 0;
        $fileCount         = 0;
        $duplicateCount    = 0;
        $totalOperations   = 0;
        $plannedMoves      = 0;
        $plannedCopies     = 0;
        $plannedSkips      = 0;
        $livePhotoGroups   = 0;

        foreach ($fileDuplicateCollection as $duplicateIdentifier => $fileDuplicate) {
            if ($this->isLivePhotoIdentifier($duplicateIdentifier)) {
                ++$livePhotoGroups;
            }

            foreach ($fileDuplicate->getRenames() as $rename) {
                $relativeSource = $this->getRelativePath($rename->getSource(), $sourceBaseDirectory);

                if (strlen($relativeSource) > $maxFilenameLength) {
                    $maxFilenameLength = strlen($relativeSource);
                }

                ++$totalOperations;
            }
        }

        // Build in-memory index of all source paths for fast occupied-checks.
        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [];

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $occupiedPaths[$rename->getSource()->getPathname()] = true;
            }
        }

        $this->io->newLine();
        $this->io->text(sprintf(
            '<fg=cyan>%s files</>',
            $options->copyFiles ? 'Copying' : 'Renaming',
        ));
        $this->io->newLine();

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            $canonicalTargetPath = $fileDuplicate->getTarget()->getPathname();

            foreach ($fileDuplicate->getRenames() as $rename) {
                $canonicalBasename = $fileDuplicate->getTarget()->getBasename(
                    '.' . $fileDuplicate->getTarget()->getExtension()
                );
                $renameBasename = $rename->getTarget()->getBasename(
                    '.' . $rename->getTarget()->getExtension()
                );
                $isDuplicateTarget = $renameBasename !== $canonicalBasename;
                $isNoOp            = $rename->getSource()->getPathname() === $rename->getTarget()->getPathname();
                $isCanonicalEntry  = $isNoOp
                    || ($options->listAll && $rename->getSource()->getPathname() === $canonicalTargetPath);

                $sourcePath = $this->getRelativePath($rename->getSource(), $sourceBaseDirectory);
                $targetPath = $this->getRelativePath($rename->getTarget(), $targetBaseDirectory);

                if ($isDuplicateTarget) {
                    $statusTag = '<fg=red>[D]</>';
                } elseif ($isCanonicalEntry) {
                    $statusTag = '<fg=blue>[O]</>';
                } else {
                    $statusTag = '<fg=green>[R]</>';
                }

                $this->io->text(sprintf(
                    ' %s <fg=yellow>%-' . $maxFilenameLength . 's</> <fg=cyan>→</> <fg=green>%s</>',
                    $statusTag,
                    $sourcePath,
                    $targetPath,
                ));

                if ($isDuplicateTarget) {
                    ++$duplicateCount;
                }

                $shouldSkip = $options->skipDuplicates && $isDuplicateTarget;

                if ($shouldSkip) {
                    $this->io->text('       <fg=red>⏭ Skipped (duplicate)</>');
                }

                $shouldPerformOperation = $shouldSkip === false && $isCanonicalEntry === false;

                if ($shouldSkip) {
                    ++$plannedSkips;
                }

                if ($shouldPerformOperation) {
                    if ($options->copyFiles) {
                        ++$plannedCopies;
                    } else {
                        ++$plannedMoves;
                    }

                    ++$fileCount;

                    if ($options->dryRun === false) {
                        $this->copyOrMoveFile(
                            $rename->getSource(),
                            $rename->getTarget(),
                            $options->copyFiles,
                            $occupiedPaths,
                        );
                    }
                }
            }
        }

        $scannedFiles = $options->scannedFiles ?? $totalOperations;

        $this->io->newLine();
        $this->io->text('<fg=cyan>Summary</>');
        $this->io->newLine();

        $rows = [
            ['Scanned files', (string) $scannedFiles],
        ];

        if ($plannedMoves > 0) {
            $rows[] = ['Planned moves', (string) $plannedMoves];
        }

        if ($plannedCopies > 0) {
            $rows[] = ['Planned copies', (string) $plannedCopies];
        }

        if ($plannedSkips > 0) {
            $rows[] = ['Planned skips', (string) $plannedSkips];
        }

        if ($livePhotoGroups > 0) {
            $rows[] = ['Live Photo groups', (string) $livePhotoGroups];
        }

        if ($duplicateCount > 0) {
            $rows[] = ['Duplicates found', (string) $duplicateCount];
        }

        if ($options->namingCollisions > 0) {
            $rows[] = ['Naming collisions', (string) $options->namingCollisions];
        }

        $rows[] = [$options->dryRun ? 'Files to process' : 'Files processed', (string) $fileCount];

        $maxLabelLength = 0;
        $maxValueLength = 0;

        foreach ($rows as $row) {
            $maxLabelLength = max($maxLabelLength, strlen($row[0]));
            $maxValueLength = max($maxValueLength, strlen($row[1]));
        }

        foreach ($rows as $row) {
            $this->io->text(sprintf(
                ' %-' . $maxLabelLength . 's  %' . $maxValueLength . 's',
                $row[0],
                $row[1],
            ));
        }

        $this->io->newLine();
    }

    /**
     * Determines if the duplicate identifier belongs to a Live Photo group.
     */
    private function isLivePhotoIdentifier(int|string $duplicateIdentifier): bool
    {
        if (!is_string($duplicateIdentifier)) {
            return false;
        }

        return str_starts_with($duplicateIdentifier, 'live-photo:');
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

        if (
            str_starts_with($normalizedBase, DIRECTORY_SEPARATOR)
            || str_starts_with($normalizedBase, '\\')
            || preg_match('/^[A-Za-z]:(?:[\\\\\\/]|$)/', $normalizedBase) === 1
        ) {
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
    /**
     * @param array<string, true> $occupiedPaths mutable index of paths currently occupied on disk
     */
    protected function copyOrMoveFile(
        SplFileInfo $sourceFileInfo,
        SplFileInfo $targetFileInfo,
        bool $copy = false,
        array &$occupiedPaths = [],
    ): void {
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

        $sourcePath = $sourceFileInfo->getPathname();
        $targetPath = $targetFileInfo->getPathname();

        if (!$sourceFileInfo->isFile()) {
            throw new RuntimeException(
                sprintf('Source file "%s" does not exist', $sourcePath),
            );
        }

        // Target already occupied by a different file (moved there earlier in the same batch).
        // Fall back to the next available duplicate suffix to prevent data loss.
        if (
            $targetPath !== $sourcePath
            && isset($occupiedPaths[$targetPath])
        ) {
            $targetFileInfo = $this->findAvailableDuplicateTarget($targetFileInfo, $occupiedPaths);
            $targetPath     = $targetFileInfo->getPathname();
        }

        if ($copy) {
            $result = copy($sourcePath, $targetPath);

            if ($result === false) {
                throw new RuntimeException(
                    sprintf('Failed to copy file to "%s"', $targetPath),
                );
            }

            // Copy adds a new occupied path without freeing the source.
            $occupiedPaths[$targetPath] = true;
        } else {
            $result = rename($sourcePath, $targetPath);

            if ($result === false) {
                throw new RuntimeException(
                    sprintf('Failed to move file to "%s"', $targetPath),
                );
            }

            // Move: source freed, target occupied.
            unset($occupiedPaths[$sourcePath]);
            $occupiedPaths[$targetPath] = true;
        }
    }

    /**
     * Finds the next available duplicate target path that is not occupied.
     *
     * @param array<string, true> $occupiedPaths current set of occupied paths
     */
    private function findAvailableDuplicateTarget(SplFileInfo $target, array $occupiedPaths): SplFileInfo
    {
        $ext      = $target->getExtension();
        $basename = $target->getBasename('.' . $ext);
        $dir      = $target->getPath();

        // Strip any existing duplicate suffix to avoid nested suffixes (e.g. -duplicate-003-duplicate-001).
        $basename = preg_replace('/' . preg_quote(self::DUPLICATE_IDENTIFIER, '/') . '\d+$/', '', $basename) ?? $basename;

        $counter = 1;

        do {
            if ($counter > self::MAX_DUPLICATE_SUFFIX) {
                throw new RuntimeException(
                    sprintf('Exceeded %d attempts finding available target for "%s"', self::MAX_DUPLICATE_SUFFIX, $basename)
                );
            }

            $candidatePath = sprintf(
                '%s%s%s%s%03d.%s',
                $dir,
                DIRECTORY_SEPARATOR,
                $basename,
                self::DUPLICATE_IDENTIFIER,
                $counter,
                $ext,
            );

            ++$counter;
        } while (isset($occupiedPaths[$candidatePath]));

        return new SplFileInfo($candidatePath);
    }
}
