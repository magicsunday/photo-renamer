<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\Output\OutputCounters;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * Executes the legacy non-ExecutionPlan rename flow behind the stable filesystem facade.
 *
 * Commands outside `rename:exif` still rely on the older `FileDuplicateCollection`
 * runtime model. This executor isolates the remaining legacy orchestration:
 * output projection, occupied-path tracking, runtime fallback moves, and final
 * summary rendering. Keeping that flow here lets `FileSystemService` stay a thin
 * command-facing facade while preserving the bounded-exception legacy path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyRenameExecutor
{
    /**
     * @param SymfonyStyle            $io                      Console IO for headings and summary output
     * @param RenameOutputRenderer    $renderer                Shared output renderer for entry projection and summary rendering
     * @param RuntimeFileMoveExecutor $runtimeFileMoveExecutor Performs concrete move operations with runtime fallback handling
     */
    public function __construct(
        private SymfonyStyle $io,
        private RenameOutputRenderer $renderer,
        private RuntimeFileMoveExecutor $runtimeFileMoveExecutor,
    ) {
    }

    /**
     * Executes the legacy rename flow for the provided duplicate collection.
     *
     * The method keeps the command-visible behavior intact: it builds output
     * entries, renders them, performs runtime collision-safe moves when not in
     * dry-run mode, and prints the same summary metrics as before.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Legacy rename set to execute
     * @param RenameOptions           $options                 Options controlling dry-run and path display
     * @param RenameResult            $result                  Aggregate pipeline result information used in the summary
     * @param list<string>|null       $showFilter              Optional output-tag visibility filter
     */
    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        RenameOptions $options = new RenameOptions(),
        RenameResult $result = new RenameResult(),
        ?array $showFilter = null,
    ): void {
        $sourceBaseDirectory = $this->normalizeBaseDirectory($options->sourceBaseDirectory);
        $livePhotoGroups     = $this->renderer->countLivePhotoGroups($fileDuplicateCollection);
        $totalOperations     = $this->renderer->countTotalOperations($fileDuplicateCollection);

        [$outputEntries, $skippedCount, $errorCount]
            = $this->renderer->buildOutputEntries($fileDuplicateCollection, $options, $result, $sourceBaseDirectory);

        $occupiedPaths = $this->buildOccupiedPaths($fileDuplicateCollection);

        $this->io->newLine();
        $this->io->text('<fg=cyan>Renaming files</>');
        $this->io->newLine();

        $counters = $this->renderOutputEntries($outputEntries, $options, $occupiedPaths, $sourceBaseDirectory, $showFilter);

        $this->renderer->renderSummary([
            'scannedFiles'     => $result->scannedFiles > 0 ? $result->scannedFiles : $totalOperations,
            'skippedCount'     => $skippedCount,
            'errorCount'       => $errorCount,
            'livePhotoGroups'  => $livePhotoGroups,
            'namingCollisions' => $result->namingCollisions,
            ...$counters->toArray(),
        ], $options->dryRun);
    }

    /**
     * Builds the occupied-path index used for runtime collision safety.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Legacy rename set whose sources should reserve disk paths
     *
     * @return array<string, true> In-memory map of occupied source paths
     */
    private function buildOccupiedPaths(FileDuplicateCollection $fileDuplicateCollection): array
    {
        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [];

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $occupiedPaths[$rename->getSource()->getPathname()] = true;
            }
        }

        return $occupiedPaths;
    }

    /**
     * Renders the projected output entries and performs the corresponding legacy moves.
     *
     * @param list<OutputEntry>   $outputEntries       Projected output entries for the legacy flow
     * @param RenameOptions       $options             Options controlling dry-run behavior
     * @param array<string, true> $occupiedPaths       Mutable occupied-path index shared with runtime moves
     * @param string|null         $sourceBaseDirectory Base directory used when entries were relativized for display
     * @param list<string>|null   $showFilter          Optional output-tag visibility filter
     *
     * @return OutputCounters Immutable render counters from the shared output module
     */
    private function renderOutputEntries(
        array $outputEntries,
        RenameOptions $options,
        array &$occupiedPaths,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
    ): OutputCounters {
        $counters = $this->renderer->renderEntryLines($outputEntries, $sourceBaseDirectory, $showFilter);

        foreach ($outputEntries as $entry) {
            if (!$entry->isRename()) {
                continue;
            }

            $absoluteSource = $entry->sortKey;
            $absoluteTarget = ($sourceBaseDirectory !== null && ($entry->targetPath !== null))
                ? $sourceBaseDirectory . DIRECTORY_SEPARATOR . $entry->targetPath
                : ($entry->targetPath ?? $absoluteSource);

            if ($entry->shouldSkip) {
                $occupiedPaths[$absoluteSource] = true;

                continue;
            }

            if ($entry->shouldPerformOperation) {
                $this->moveFile(
                    new SplFileInfo($absoluteSource),
                    new SplFileInfo($absoluteTarget),
                    $occupiedPaths,
                    $options->dryRun,
                );
            }
        }

        return $counters;
    }

    /**
     * Normalizes the optional base directory used for relative display output.
     *
     * @param string|null $baseDirectory Raw base-directory option value
     *
     * @return string|null Trimmed base directory or null when empty
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
     * Moves one legacy source file to its target using the runtime fallback layer.
     *
     * @param SplFileInfo         $sourceFileInfo Source file to move
     * @param SplFileInfo         $targetFileInfo Target file to create or redirect to
     * @param array<string, true> $occupiedPaths  Mutable occupied-path index shared by the batch
     * @param bool                $dryRun         When true, record path occupancy without touching the filesystem
     */
    private function moveFile(
        SplFileInfo $sourceFileInfo,
        SplFileInfo $targetFileInfo,
        array &$occupiedPaths = [],
        bool $dryRun = false,
    ): void {
        $this->runtimeFileMoveExecutor->moveFileByPath(
            $sourceFileInfo->getPathname(),
            $targetFileInfo->getPathname(),
            $occupiedPaths,
            $dryRun,
        );
    }
}
