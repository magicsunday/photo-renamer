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
use MagicSunday\Renamer\Constants;
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
use function copy;
use function file_exists;
use function is_dir;
use function is_string;
use function max;
use function mkdir;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function rename;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Handles all direct file system interactions: creating file iterators, counting files,
 * executing the actual rename/copy operations, printing the summary output table,
 * and resolving runtime target collisions via the {@see findAvailableDuplicateTarget()}
 * fallback. Tracks occupied paths in-memory to prevent data loss during batch moves.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class FileSystemService implements FileSystemServiceInterface
{
    /**
     * @param SymfonyStyle $io Console IO for progress bars, status output and error messages
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
                $relativeSource = self::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);

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

        // Seed with existing files in the target directory so pre-existing files
        // are not overwritten by the runtime collision fallback.
        if (
            $targetBaseDirectory !== null
            && $targetBaseDirectory !== $sourceBaseDirectory
            && is_dir($targetBaseDirectory)
        ) {
            $targetIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetBaseDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var SplFileInfo $targetFile */
            foreach ($targetIterator as $targetFile) {
                $occupiedPaths[$targetFile->getPathname()] = true;
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
            $canonicalBasename   = $fileDuplicate->getTarget()->getBasename(
                '.' . $fileDuplicate->getTarget()->getExtension()
            );

            foreach ($fileDuplicate->getRenames() as $rename) {
                $renameBasename = $rename->getTarget()->getBasename(
                    '.' . $rename->getTarget()->getExtension()
                );
                $isDuplicateTarget = $renameBasename !== $canonicalBasename;
                $isNoOp            = $rename->getSource()->getPathname() === $rename->getTarget()->getPathname();
                $isCanonicalEntry  = $isNoOp
                    || ($options->listAll && $rename->getSource()->getPathname() === $canonicalTargetPath);

                $sourcePath = self::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);
                $targetPath = self::relativizePath($rename->getTarget()->getPathname(), $targetBaseDirectory);

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
     * Checks whether a duplicate identifier string uses the "live-photo:" prefix,
     * indicating the group was formed from Apple Live Photo content identifiers.
     *
     * @param int|string $duplicateIdentifier Group key to inspect
     *
     * @return bool True when the identifier starts with "live-photo:"
     */
    private function isLivePhotoIdentifier(int|string $duplicateIdentifier): bool
    {
        if (!is_string($duplicateIdentifier)) {
            return false;
        }

        return str_starts_with($duplicateIdentifier, Constants::LIVE_PHOTO_IDENTIFIER_PREFIX);
    }

    /**
     * Converts an absolute pathname to a display-friendly relative path by stripping
     * the base directory prefix and prepending the base directory's own name. Falls back
     * to the full pathname when the path does not start with the base or when the base
     * directory is a relative path.
     *
     * @param string      $pathname      Absolute file path
     * @param string|null $baseDirectory Normalized base directory (trailing separator stripped)
     *
     * @return string Relative or absolute path suitable for display
     */
    public static function relativizePath(string $pathname, ?string $baseDirectory): string
    {
        if (($baseDirectory === null) || ($baseDirectory === '')) {
            return $pathname;
        }

        $normalizedBase = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        if ($normalizedBase === '') {
            return $pathname;
        }

        if (
            !str_starts_with($normalizedBase, DIRECTORY_SEPARATOR)
            && !str_starts_with($normalizedBase, '\\')
            && (preg_match('/^[A-Za-z]:(?:[\\\\\\/]|$)/', $normalizedBase) !== 1)
        ) {
            return $pathname;
        }

        $prefix = $normalizedBase . DIRECTORY_SEPARATOR;

        if (str_starts_with($pathname, $prefix)) {
            $relativePath = substr($pathname, strlen($prefix));
            $baseName     = basename($normalizedBase);

            if (($baseName === '') || ($baseName === DIRECTORY_SEPARATOR)) {
                return $relativePath;
            }

            return $baseName . DIRECTORY_SEPARATOR . $relativePath;
        }

        return $pathname;
    }

    /**
     * Strips trailing directory separators from a base directory string.
     * Returns null for null or empty inputs.
     *
     * @param string|null $baseDirectory Raw base directory path
     *
     * @return string|null Trimmed path, or null
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
     * Copies or moves a single file from source to target, creating directories as needed.
     * When the target path is already occupied (by a file moved earlier in the same batch),
     * falls back to {@see findAvailableDuplicateTarget()} to prevent data loss. Updates the
     * occupied-paths index to reflect the new file system state.
     *
     * @param SplFileInfo         $sourceFileInfo Source file to move or copy
     * @param SplFileInfo         $targetFileInfo Intended target path
     * @param bool                $copy           When true, copy instead of move
     * @param array<string, true> $occupiedPaths  Mutable index of paths currently occupied on disk
     *
     * @throws RuntimeException When the directory cannot be created or the file operation fails
     */
    private function copyOrMoveFile(
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
            $action = 'copy';
        } else {
            $result = rename($sourcePath, $targetPath);
            $action = 'move';
        }

        if ($result === false) {
            throw new RuntimeException(
                sprintf('Failed to %s file to "%s"', $action, $targetPath),
            );
        }

        if (!$copy) {
            // Move: source freed.
            unset($occupiedPaths[$sourcePath]);
        }

        $occupiedPaths[$targetPath] = true;
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
        $basename = preg_replace('/' . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '\d+$/', '', $basename) ?? $basename;

        $counter = 1;

        do {
            if ($counter > Constants::MAX_DUPLICATE_SUFFIX) {
                throw new RuntimeException(
                    sprintf('Exceeded %d attempts finding available target for "%s"', Constants::MAX_DUPLICATE_SUFFIX, $basename)
                );
            }

            $candidatePath = sprintf(
                '%s%s%s%s%03d.%s',
                $dir,
                DIRECTORY_SEPARATOR,
                $basename,
                Constants::DUPLICATE_IDENTIFIER,
                $counter,
                $ext,
            );

            ++$counter;
        } while (isset($occupiedPaths[$candidatePath]));

        return new SplFileInfo($candidatePath);
    }
}
