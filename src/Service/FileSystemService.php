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
use MagicSunday\Renamer\Command\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function basename;
use function copy;
use function count;
use function file_exists;
use function in_array;
use function is_dir;
use function is_string;
use function max;
use function mb_strlen;
use function mkdir;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function rename;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function usort;

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
            $recursiveIterator = new RecursiveRegexFileFilterIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                ),
                '/^.+$/'
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
     * @param list<string>|null       $showFilter              When set, only output entries matching these tags are shown
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
        ?array $showFilter = null,
    ): void {
        $sourceBaseDirectory = $this->normalizeBaseDirectory($options->sourceBaseDirectory);
        $targetBaseDirectory = $this->normalizeBaseDirectory($options->targetBaseDirectory);

        $livePhotoGroups = 0;
        $totalOperations = 0;

        foreach ($fileDuplicateCollection as $duplicateIdentifier => $fileDuplicate) {
            if ($this->isLivePhotoIdentifier($duplicateIdentifier)) {
                ++$livePhotoGroups;
            }

            $totalOperations += count($fileDuplicate->getRenames());
        }

        [$outputEntries, $maxFilenameLength, $skippedCount, $errorCount]
            = $this->buildOutputEntries($fileDuplicateCollection, $options, $sourceBaseDirectory, $targetBaseDirectory);

        $occupiedPaths = $this->buildOccupiedPaths($fileDuplicateCollection, $targetBaseDirectory, $sourceBaseDirectory);

        $this->io->newLine();
        $this->io->text(sprintf('<fg=cyan>%s files</>', $options->copyFiles ? 'Copying' : 'Renaming'));
        $this->io->newLine();

        $counters = $this->renderOutputEntries($outputEntries, $maxFilenameLength, $options, $occupiedPaths, $showFilter);

        $this->renderSummary([
            'scannedFiles'     => $options->scannedFiles ?? $totalOperations,
            'skippedCount'     => $skippedCount,
            'errorCount'       => $errorCount,
            'livePhotoGroups'  => $livePhotoGroups,
            'namingCollisions' => $options->namingCollisions,
            ...$counters,
        ], $options->dryRun);
    }

    /**
     * Builds a merged, path-sorted list of all output entries from rename operations
     * and skipped files for display during the rename phase.
     *
     * @return array{list<array<string, mixed>>, int, int, int} Tuple of [entries, maxFilenameLength, skippedCount, errorCount]
     */
    private function buildOutputEntries(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options,
        ?string $sourceBaseDirectory,
        ?string $targetBaseDirectory,
    ): array {
        $maxFilenameLength = 0;

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $relativeSource = self::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);

                if (mb_strlen($relativeSource) > $maxFilenameLength) {
                    $maxFilenameLength = mb_strlen($relativeSource);
                }
            }
        }

        foreach ($options->skippedFiles as $skippedFile) {
            $relativeSource = self::relativizePath($skippedFile->getFile()->getPathname(), $sourceBaseDirectory);

            if (mb_strlen($relativeSource) > $maxFilenameLength) {
                $maxFilenameLength = mb_strlen($relativeSource);
            }
        }

        /** @var list<array<string, mixed>> $outputEntries */
        $outputEntries = [];

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
                $isDuplicateTarget = $renameBasename !== $canonicalBasename
                    && str_contains($renameBasename, Constants::DUPLICATE_IDENTIFIER);
                $isNoOp           = $rename->getSource()->getPathname() === $rename->getTarget()->getPathname();
                $isCanonicalEntry = $isNoOp
                    || ($options->listAll && $rename->getSource()->getPathname() === $canonicalTargetPath);

                $sourcePath = self::relativizePath($rename->getSource()->getPathname(), $sourceBaseDirectory);
                $targetPath = self::relativizePath($rename->getTarget()->getPathname(), $targetBaseDirectory);

                if ($isDuplicateTarget) {
                    $tag = OutputEntryTag::Duplicate;
                } elseif ($isCanonicalEntry) {
                    $tag = OutputEntryTag::Original;
                } else {
                    $tag = OutputEntryTag::Rename;
                }

                $shouldSkip             = $options->skipDuplicates && $isDuplicateTarget;
                $shouldPerformOperation = ($shouldSkip === false) && ($isCanonicalEntry === false);

                $outputEntries[] = [
                    'sortKey'                => $rename->getSource()->getPathname(),
                    'type'                   => 'rename',
                    'sourcePath'             => $sourcePath,
                    'targetPath'             => $targetPath,
                    'tag'                    => $tag,
                    'isDuplicateTarget'      => $isDuplicateTarget,
                    'shouldSkip'             => $shouldSkip,
                    'shouldPerformOperation' => $shouldPerformOperation,
                    'rename'                 => $rename,
                ];
            }
        }

        $skippedCount = 0;
        $errorCount   = 0;

        foreach ($options->skippedFiles as $skippedFile) {
            if ($skippedFile->isError()) {
                ++$errorCount;
            } else {
                ++$skippedCount;
            }

            $outputEntries[] = [
                'sortKey'    => $skippedFile->getFile()->getPathname(),
                'type'       => 'skip',
                'sourcePath' => self::relativizePath($skippedFile->getFile()->getPathname(), $sourceBaseDirectory),
                'reason'     => ucfirst($skippedFile->getReason()),
                'tag'        => $skippedFile->isError() ? OutputEntryTag::Error : OutputEntryTag::Skipped,
            ];
        }

        usort($outputEntries, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);

        return [$outputEntries, $maxFilenameLength, $skippedCount, $errorCount];
    }

    /**
     * Builds an in-memory index of all occupied file paths for collision detection.
     *
     * @return array<string, true>
     */
    private function buildOccupiedPaths(
        FileDuplicateCollection $fileDuplicateCollection,
        ?string $targetBaseDirectory,
        ?string $sourceBaseDirectory,
    ): array {
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

        return $occupiedPaths;
    }

    /**
     * Renders the filtered output entries and executes file operations.
     *
     * @param list<array<string, mixed>> $outputEntries
     * @param array<string, true>        $occupiedPaths
     * @param list<string>|null          $showFilter
     *
     * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedCopies: int, plannedSkips: int}
     */
    private function renderOutputEntries(
        array $outputEntries,
        int $maxFilenameLength,
        RenameOptions $options,
        array &$occupiedPaths,
        ?array $showFilter = null,
    ): array {
        $fileCount      = 0;
        $duplicateCount = 0;
        $plannedMoves   = 0;
        $plannedCopies  = 0;
        $plannedSkips   = 0;

        foreach ($outputEntries as $entry) {
            /** @var string $sourcePath */
            $sourcePath = $entry['sourcePath'];

            /** @var OutputEntryTag $entryTag */
            $entryTag = $entry['tag'];

            // Compensate for multi-byte characters: sprintf pads by byte length,
            // but the terminal aligns by display width (mb_strlen).
            $padWidth = $maxFilenameLength + strlen($sourcePath) - mb_strlen($sourcePath);

            $formatString = ' %s <fg=yellow>%-' . $padWidth . 's</> <fg=cyan>→</> %s';

            if ($entry['type'] === 'skip') {
                /** @var string $reason */
                $reason = $entry['reason'];

                if ($showFilter === null || in_array($entryTag->letter(), $showFilter, true)) {
                    $this->io->text(sprintf(
                        $formatString,
                        $entryTag->formattedTag(),
                        $sourcePath,
                        sprintf('<fg=%s>%s</>', $entryTag->color(), $reason),
                    ));
                }

                continue;
            }

            /** @var string $targetPath */
            $targetPath = $entry['targetPath'];

            /** @var bool $isDuplicateTarget */
            $isDuplicateTarget = $entry['isDuplicateTarget'];

            /** @var bool $shouldSkip */
            $shouldSkip = $entry['shouldSkip'];

            /** @var bool $shouldPerformOperation */
            $shouldPerformOperation = $entry['shouldPerformOperation'];

            /** @var Rename $rename */
            $rename = $entry['rename'];

            if ($showFilter === null || in_array($entryTag->letter(), $showFilter, true)) {
                $this->io->text(sprintf(
                    $formatString,
                    $entryTag->formattedTag(),
                    $sourcePath,
                    sprintf('<fg=green>%s</>', $targetPath),
                ));

                if ($shouldSkip) {
                    $this->io->text('       <fg=red>⏭ Skipped (duplicate)</>');
                }
            }

            if ($isDuplicateTarget) {
                ++$duplicateCount;
            }

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

        return [
            'fileCount'      => $fileCount,
            'duplicateCount' => $duplicateCount,
            'plannedMoves'   => $plannedMoves,
            'plannedCopies'  => $plannedCopies,
            'plannedSkips'   => $plannedSkips,
        ];
    }

    /**
     * Renders the summary table with file counts and statistics.
     *
     * @param array<string, int> $counters
     */
    private function renderSummary(array $counters, bool $dryRun): void
    {
        $this->io->newLine();
        $this->io->text('<fg=cyan>Summary</>');
        $this->io->newLine();

        $rows = [
            ['Scanned files', (string) $counters['scannedFiles']],
        ];

        if ($counters['skippedCount'] > 0) {
            $rows[] = ['Skipped (no metadata)', (string) $counters['skippedCount']];
        }

        if ($counters['errorCount'] > 0) {
            $rows[] = ['Skipped (read errors)', (string) $counters['errorCount']];
        }

        if ($counters['plannedMoves'] > 0) {
            $rows[] = ['Planned moves', (string) $counters['plannedMoves']];
        }

        if ($counters['plannedCopies'] > 0) {
            $rows[] = ['Planned copies', (string) $counters['plannedCopies']];
        }

        if ($counters['plannedSkips'] > 0) {
            $rows[] = ['Planned skips', (string) $counters['plannedSkips']];
        }

        if ($counters['livePhotoGroups'] > 0) {
            $rows[] = ['Live Photo groups', (string) $counters['livePhotoGroups']];
        }

        if ($counters['duplicateCount'] > 0) {
            $rows[] = ['Duplicates found', (string) $counters['duplicateCount']];
        }

        if ($counters['namingCollisions'] > 0) {
            $rows[] = ['Naming collisions', (string) $counters['namingCollisions']];
        }

        $rows[] = [$dryRun ? 'Files to process' : 'Files processed', (string) $counters['fileCount']];

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
