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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function copy;
use function file_exists;
use function in_array;
use function is_dir;
use function mb_strlen;
use function mkdir;
use function preg_match;
use function rename;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Handles all direct file system interactions: creating file iterators, counting files,
 * executing the actual rename/copy operations, and resolving runtime target collisions
 * via the {@see findAvailableDuplicateTarget()} fallback. Delegates output entry building
 * and summary rendering to {@see RenameOutputRenderer}. Tracks occupied paths in-memory
 * to prevent data loss during batch moves.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class FileSystemService implements FileSystemServiceInterface
{
    /**
     * @param SymfonyStyle         $io       Console IO for progress bars, status output and error messages
     * @param RenameOutputRenderer $renderer Handles output entry building and summary rendering
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly RenameOutputRenderer $renderer,
    ) {
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
     * @param RenameResult            $result                  Pipeline-computed results (scanned files, collisions, skips)
     * @param list<string>|null       $showFilter              When set, only output entries matching these tags are shown
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
        RenameResult $result = new RenameResult(),
        ?array $showFilter = null,
    ): void {
        $sourceBaseDirectory = $this->normalizeBaseDirectory($options->sourceBaseDirectory);
        $targetBaseDirectory = $this->normalizeBaseDirectory($options->targetBaseDirectory);

        $livePhotoGroups = $this->renderer->countLivePhotoGroups($fileDuplicateCollection);
        $totalOperations = $this->renderer->countTotalOperations($fileDuplicateCollection);

        [$outputEntries, $maxFilenameLength, $skippedCount, $errorCount]
            = $this->renderer->buildOutputEntries($fileDuplicateCollection, $options, $result, $sourceBaseDirectory, $targetBaseDirectory);

        $occupiedPaths = $this->buildOccupiedPaths($fileDuplicateCollection, $targetBaseDirectory, $sourceBaseDirectory);

        $this->io->newLine();
        $this->io->text(sprintf('<fg=cyan>%s files</>', $options->copyFiles ? 'Copying' : 'Renaming'));
        $this->io->newLine();

        $counters = $this->renderOutputEntries($outputEntries, $maxFilenameLength, $options, $occupiedPaths, $showFilter);

        $this->renderer->renderSummary([
            'scannedFiles'     => $result->scannedFiles > 0 ? $result->scannedFiles : $totalOperations,
            'skippedCount'     => $skippedCount,
            'errorCount'       => $errorCount,
            'livePhotoGroups'  => $livePhotoGroups,
            'namingCollisions' => $result->namingCollisions,
            ...$counters,
        ], $options->dryRun);
    }

    /**
     * Scans a directory recursively and returns all file paths as an occupied-paths index.
     *
     * @param string $directory Absolute path to the directory to scan
     *
     * @return array<string, true>
     */
    public static function scanDirectoryPaths(string $directory): array
    {
        $paths    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $paths[$file->getPathname()] = true;
        }

        return $paths;
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
            $occupiedPaths += self::scanDirectoryPaths($targetBaseDirectory);
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
                    $this->highlightDiff($sourcePath, $targetPath, 'green'),
                ));

                if ($shouldSkip) {
                    $skipReason = match ($entryTag) {
                        OutputEntryTag::Fallback => 'fallback date',
                        OutputEntryTag::Warning  => 'suspicious date',
                        default                  => 'duplicate',
                    };
                    $this->io->text(sprintf('       <fg=red>⏭  Skipped (%s)</>', $skipReason));
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
     * Formats a target path with only the changed characters highlighted in bold.
     *
     * First isolates the differing region via common prefix/suffix. Then applies
     * LCS within that region to keep common substrings (e.g. "15-08") in the base
     * color. Short common runs (< 3 chars) within the diff are merged into the
     * highlight to avoid a confusing flickering pattern from single-character matches.
     *
     * @param string $source    Source display path
     * @param string $target    Target display path
     * @param string $baseColor Symfony Console color name for unchanged text
     *
     * @return string Formatted string with diff highlighted
     */
    private function highlightDiff(string $source, string $target, string $baseColor): string
    {
        if ($source === $target) {
            return sprintf('<fg=%s>%s</>', $baseColor, $target);
        }

        $sourceChars = mb_str_split($source);
        $targetChars = mb_str_split($target);
        $sLen        = count($sourceChars);
        $tLen        = count($targetChars);
        $minLen      = min($sLen, $tLen);

        // Common prefix.
        $prefixLen = 0;

        while (($prefixLen < $minLen) && ($sourceChars[$prefixLen] === $targetChars[$prefixLen])) {
            ++$prefixLen;
        }

        // Common suffix.
        $suffixLen = 0;

        while (
            ($suffixLen < ($minLen - $prefixLen))
            && ($sourceChars[$sLen - 1 - $suffixLen] === $targetChars[$tLen - 1 - $suffixLen])
        ) {
            ++$suffixLen;
        }

        $prefix = implode('', array_slice($targetChars, 0, $prefixLen));
        $suffix = $suffixLen > 0 ? implode('', array_slice($targetChars, $tLen - $suffixLen)) : '';

        $sourceMid = array_slice($sourceChars, $prefixLen, $sLen - $prefixLen - $suffixLen);
        $targetMid = array_slice($targetChars, $prefixLen, $tLen - $prefixLen - $suffixLen);

        if ($targetMid === []) {
            return sprintf('<fg=%s>%s</>', $baseColor, $target);
        }

        // LCS within the diff region to find matching runs.
        $inLcs = $this->computeLcsFlags($sourceMid, $targetMid);

        // Merge short common runs (< 3 chars) into highlights to avoid flickering
        // from accidental single-character matches (e.g. a lone "-" or digit).
        $inLcs = $this->mergeShortCommonRuns($inLcs, 3);

        // Build formatted middle.
        $midFormatted = '';
        $buffer       = '';
        $inHighlight  = false;

        foreach ($targetMid as $j => $char) {
            $isChanged = !$inLcs[$j];

            if (($isChanged !== $inHighlight) && ($buffer !== '')) {
                $midFormatted .= $inHighlight
                    ? sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $buffer)
                    : sprintf('<fg=%s>%s</>', $baseColor, $buffer);
                $buffer = '';
            }

            $inHighlight = $isChanged;
            $buffer .= $char;
        }

        $midFormatted .= $inHighlight
            ? sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $buffer)
            : sprintf('<fg=%s>%s</>', $baseColor, $buffer);

        return sprintf('<fg=%s>%s</>', $baseColor, $prefix)
            . $midFormatted
            . sprintf('<fg=%s>%s</>', $baseColor, $suffix);
    }

    /**
     * Merges short common runs (LCS matches) that are shorter than the threshold
     * into the surrounding highlight. This prevents distracting single-character
     * or two-character matches from flickering between colors.
     *
     * @param array<int, bool> $flags     Per-character LCS match flags
     * @param int              $threshold Minimum run length to keep as common
     *
     * @return array<int, bool> Adjusted flags with short runs merged into highlights
     */
    private function mergeShortCommonRuns(array $flags, int $threshold): array
    {
        $count    = count($flags);
        $runStart = 0;

        while ($runStart < $count) {
            // Skip highlighted (false) regions.
            if (!$flags[$runStart]) {
                ++$runStart;

                continue;
            }

            // Found a common (true) run. Measure its length.
            $runEnd = $runStart;

            while (($runEnd < $count) && $flags[$runEnd]) {
                ++$runEnd;
            }

            $runLength = $runEnd - $runStart;

            // Short common run → merge into highlight. Single-character matches
            // are likely coincidental (e.g. "0" matching between "08" and "10").
            if ($runLength < $threshold) {
                for ($k = $runStart; $k < $runEnd; ++$k) {
                    $flags[$k] = false;
                }
            }

            $runStart = $runEnd;
        }

        return $flags;
    }

    /**
     * Computes which characters in the target array are part of the Longest Common
     * Subsequence (LCS) with the source array. Returns a boolean array where true
     * means the target character at that position matches a source character.
     *
     * @param list<string> $sourceChars Source characters
     * @param list<string> $targetChars Target characters
     *
     * @return array<int, bool> True for each target character that is part of the LCS
     */
    private function computeLcsFlags(array $sourceChars, array $targetChars): array
    {
        $sLen = count($sourceChars);
        $tLen = count($targetChars);

        // Build LCS length table (dimensions: (sLen+1) x (tLen+1), initialized to 0).
        $dp = [];

        for ($i = 0; $i <= $sLen; ++$i) {
            $dp[$i] = array_fill(0, $tLen + 1, 0);
        }

        for ($i = 1; $i <= $sLen; ++$i) {
            for ($j = 1; $j <= $tLen; ++$j) {
                $dp[$i][$j] = ($sourceChars[$i - 1] === $targetChars[$j - 1])
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        // Backtrack to mark which target characters are in the LCS.
        $inLcs = array_fill(0, $tLen, false);
        $i     = $sLen;
        $j     = $tLen;

        while (($i > 0) && ($j > 0)) {
            if ($sourceChars[$i - 1] === $targetChars[$j - 1]) {
                $inLcs[$j - 1] = true;
                --$i;
                --$j;
            } elseif ($dp[$i - 1][$j] > $dp[$i][$j - 1]) {
                --$i;
            } else {
                --$j;
            }
        }

        return $inLcs;
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
            return substr($pathname, strlen($prefix));
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
            $result = @copy($sourcePath, $targetPath);
            $action = 'copy';
        } else {
            $result = @rename($sourcePath, $targetPath);
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
        $basename = FileHelper::basenameWithoutExtension($target);
        $dir      = $target->getPath();

        // Strip any existing duplicate suffix to avoid nested suffixes (e.g. -duplicate-003-duplicate-001).
        $basename = FileHelper::stripDuplicateSuffix($basename);

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
