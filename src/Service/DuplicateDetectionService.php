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
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_column;
use function array_key_exists;
use function count;
use function is_dir;
use function is_string;
use function sprintf;
use function str_contains;
use function strtolower;
use function substr_count;
use function trim;

/**
 * Central orchestrator of the rename pipeline's grouping and suffix-assignment phases.
 *
 * Phase 1 ({@see groupFilesByDuplicateIdentifier}): scans files, applies the rename strategy
 * to compute target filenames, groups them by duplicate identifier, and builds the content
 * identifier map needed for Live Photo companion detection.
 *
 * Phase 2 ({@see createDuplicateFilenames}): walks each group, selects the canonical file,
 * detects Live Photo companions, applies hash sub-grouping for naming collisions, and assigns
 * sequential "-duplicate-NNN" suffixes to remaining files. Maintains an in-memory disk index
 * to avoid stat() calls when checking target path availability.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class DuplicateDetectionService implements DuplicateDetectionServiceInterface
{
    /**
     * Absolute path to the directory being scanned for source files.
     */
    private string $sourceDirectory;

    /**
     * Absolute path to the directory where renamed files are placed.
     */
    private string $targetDirectory;

    /**
     * When true, duplicate targets preserve the source file's original extension
     * instead of inheriting the canonical target's extension.
     */
    private bool $useFileExtensionFromSource = false;

    /**
     * Number of groups where content-hash sub-grouping was applied because
     * multiple distinct file contents shared the same target basename.
     */
    private int $namingCollisions = 0;

    /**
     * In-memory index of all file paths discovered during scanning.
     * Used instead of stat() calls to check whether a target path is occupied.
     *
     * @var array<string, true>
     */
    private array $diskIndex = [];

    /**
     * Map from source pathname to normalized Live Photo content identifier.
     * Built during grouping, used for companion detection in createDuplicateFilenames.
     *
     * @var array<string, string>
     */
    private array $contentIdentifierMap = [];

    /**
     * @param SymfonyStyle           $io                     Console IO for progress bars and error output
     * @param HashSubGroupingService $hashSubGroupingService Service for content-hash-based sub-grouping
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly HashSubGroupingService $hashSubGroupingService,
    ) {
    }

    /**
     * Defines the directory that will be scanned for potential duplicates.
     *
     * @param string $sourceDirectory absolute path to the directory being analysed
     *
     * @return DuplicateDetectionService fluent reference for method chaining
     */
    public function setSourceDirectory(string $sourceDirectory): DuplicateDetectionService
    {
        $this->sourceDirectory = $sourceDirectory;

        return $this;
    }

    /**
     * Sets the directory in which renamed or copied files should be placed.
     *
     * @param string $targetDirectory absolute path to the destination directory
     *
     * @return DuplicateDetectionService fluent reference for method chaining
     */
    public function setTargetDirectory(string $targetDirectory): DuplicateDetectionService
    {
        $this->targetDirectory = $targetDirectory;

        return $this;
    }

    /**
     * Controls whether the original source file extension should be preserved for duplicates.
     *
     * @param bool $useFileExtensionFromSource when true the source file extension is retained in duplicates
     *
     * @return DuplicateDetectionService fluent reference for method chaining
     */
    public function setUseFileExtensionFromSource(bool $useFileExtensionFromSource): DuplicateDetectionService
    {
        $this->useFileExtensionFromSource = $useFileExtensionFromSource;

        return $this;
    }

    /**
     * Returns the number of groups where content-hash sub-grouping was applied.
     */
    public function getNamingCollisions(): int
    {
        return $this->namingCollisions;
    }

    /**
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner>    $iterator                    iterator yielding candidate files
     * @param RenameStrategyInterface              $renameStrategy              strategy used to generate target filenames
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy strategy that identifies duplicate groups
     *
     * @return FileDuplicateCollection collection describing discovered duplicate groups
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
    ): FileDuplicateCollection {
        // Collect and sort files: parent directories before subdirectories.
        /** @var list<SplFileInfo> $files */
        $files = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $files[] = $fileInfo;
        }

        $decorated = [];

        foreach ($files as $file) {
            $decorated[] = [substr_count($file->getPath(), DIRECTORY_SEPARATOR), $file->getPathname(), $file];
        }

        usort($decorated, static fn (array $a, array $b): int => $a[0] !== $b[0] ? $a[0] <=> $b[0] : $a[1] <=> $b[1]);
        $files = array_column($decorated, 2);

        // Build in-memory disk index to avoid stat() calls during planning.
        $this->diskIndex            = [];
        $this->contentIdentifierMap = [];

        foreach ($files as $file) {
            $this->diskIndex[$file->getPathname()] = true;
        }

        if ($this->targetDirectory !== $this->sourceDirectory && is_dir($this->targetDirectory)) {
            $targetIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->targetDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var SplFileInfo $targetFile */
            foreach ($targetIterator as $targetFile) {
                $this->diskIndex[$targetFile->getPathname()] = true;
            }
        }

        $progressBar = $this->startProgressBar(count($files));

        $fileDuplicateCollection = new FileDuplicateCollection();
        /**
         * @var array<string, array{
         *     duplicateIdentifier: string|null,
         *     pendingFiles: list<SplFileInfo>,
         *     target: SplFileInfo|null,
         *     captureDate: string|null
         * }> $contentIdentifierCache
         */
        $contentIdentifierCache = [];

        foreach ($files as $sourceFileInfo) {
            // The resulting file object
            $targetFileInfo = $this->getTargetFileInfo(
                $sourceFileInfo,
                $renameStrategy
            );

            $normalizedContentIdentifier = $this->resolveNormalizedContentIdentifier(
                $renameStrategy,
                $sourceFileInfo,
            );

            // Store content identifier for companion detection in createDuplicateFilenames.
            if ($normalizedContentIdentifier !== null) {
                $this->contentIdentifierMap[$sourceFileInfo->getPathname()] = $normalizedContentIdentifier;
            }

            $contentIdentifierCacheEntry = null;

            if ($normalizedContentIdentifier !== null) {
                if (!array_key_exists($normalizedContentIdentifier, $contentIdentifierCache)) {
                    $contentIdentifierCache[$normalizedContentIdentifier] = [
                        'duplicateIdentifier' => null,
                        'pendingFiles'        => [],
                        'target'              => null,
                        'captureDate'         => null,
                    ];
                }

                $contentIdentifierCacheEntry = &$contentIdentifierCache[$normalizedContentIdentifier];
            }

            if (!$targetFileInfo instanceof SplFileInfo) {
                if ($contentIdentifierCacheEntry !== null) {
                    $cachedDuplicateIdentifier = $contentIdentifierCacheEntry['duplicateIdentifier'];

                    if (
                        is_string($cachedDuplicateIdentifier)
                        && $fileDuplicateCollection->has($cachedDuplicateIdentifier)
                    ) {
                        $existingDuplicate = $fileDuplicateCollection->get($cachedDuplicateIdentifier);

                        if ($existingDuplicate instanceof FileDuplicate) {
                            $existingDuplicate->addFile($sourceFileInfo);
                        }
                    } else {
                        $contentIdentifierCacheEntry['pendingFiles'][] = $sourceFileInfo;
                    }
                }

                unset($contentIdentifierCacheEntry);

                $progressBar->advance();

                continue;
            }

            try {
                $duplicateIdentifier = $duplicateIdentifierStrategy->generateIdentifier(
                    $sourceFileInfo,
                    $targetFileInfo
                );
            } catch (HashComputationException $exception) {
                $this->io->error($exception->getMessage());

                $progressBar->advance();

                continue;
            }

            if ($duplicateIdentifier === false) {
                if ($contentIdentifierCacheEntry !== null) {
                    $contentIdentifierCacheEntry['pendingFiles'][] = $sourceFileInfo;
                    $contentIdentifierCacheEntry['target']         = $targetFileInfo;
                }

                unset($contentIdentifierCacheEntry);

                continue;
            }

            // Create duplicate object storing relevant data
            $fileDuplicate = new FileDuplicate();
            $fileDuplicate
                ->addFile($sourceFileInfo)
                ->setTarget($targetFileInfo);

            if ($fileDuplicateCollection->has($duplicateIdentifier)) {
                /** @var FileDuplicate $fileDuplicate */
                $fileDuplicate = $fileDuplicateCollection->get($duplicateIdentifier);

                $this->promoteLivePhotoTargetIfNecessary(
                    $duplicateIdentifier,
                    $fileDuplicate,
                    $targetFileInfo,
                );

                $fileDuplicate->addFile($sourceFileInfo);
            } else {
                $fileDuplicateCollection->set($duplicateIdentifier, $fileDuplicate);
            }

            if ($contentIdentifierCacheEntry !== null) {
                $contentIdentifierCacheEntry['duplicateIdentifier'] = $duplicateIdentifier;
                $contentIdentifierCacheEntry['target']              = $fileDuplicate->getTarget();
                $contentIdentifierCacheEntry['captureDate']         = $fileDuplicate->getTarget()
                    ->getBasename('.' . $fileDuplicate->getTarget()->getExtension());

                foreach ($contentIdentifierCacheEntry['pendingFiles'] as $pendingFile) {
                    $fileDuplicate->addFile($pendingFile);
                }

                $contentIdentifierCacheEntry['pendingFiles'] = [];
            }

            unset($contentIdentifierCacheEntry);

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Creates consecutive filenames for duplicate files in the supplied collection.
     *
     * NOTE: {@see groupFilesByDuplicateIdentifier()} must be called first to populate
     * {@see $contentIdentifierMap}, which is required for Live Photo companion detection.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection collection whose entries should receive duplicate filenames
     *
     * @return FileDuplicateCollection updated collection with rename operations populated
     */
    public function createDuplicateFilenames(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $skipHashSubGrouping = false,
    ): FileDuplicateCollection {
        $this->namingCollisions = 0;

        // Ensure disk index is populated when called without prior groupFilesByDuplicateIdentifier.
        if ($this->diskIndex === []) {
            foreach ($fileDuplicateCollection as $fileDuplicate) {
                foreach ($fileDuplicate->getFiles() as $fileInfo) {
                    $this->diskIndex[$fileInfo->getPathname()] = true;
                }
            }
        }

        $progressBar = $this->startProgressBar($fileDuplicateCollection->count());

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getFiles() as $renameSourceFileInfo) {
                $renameTargetFileExtension = $fileDuplicate->getTarget()->getExtension();

                // Modify the target file extension if the file extension from the source should be used.
                // This allows us to rename different file types but with the same name.
                if ($this->useFileExtensionFromSource) {
                    $renameTargetFileExtension = $renameSourceFileInfo->getExtension();
                }

                $targetPathname = $this->getTargetPathname(
                    $renameSourceFileInfo,
                    $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension())
                    . '.' . $renameTargetFileExtension
                );

                $renameTargetFileInfo = new SplFileInfo($targetPathname);

                $fileDuplicate->addRename(
                    new Rename(
                        $renameSourceFileInfo,
                        $renameTargetFileInfo
                    )
                );
            }

            /** @var RenameList $renames */
            $renames = $fileDuplicate->getRenames();

            $canonicalTargetPath     = $fileDuplicate->getTarget()->getPathname();
            $canonicalTargetBasename = $fileDuplicate->getTarget()->getBasename(
                '.' . $fileDuplicate->getTarget()->getExtension()
            );

            /** @var Rename|null $canonicalRename */
            $canonicalRename    = null;
            $canonicalHasLpId   = false;
            $canonicalExactName = false;

            foreach ($renames as $rename) {
                if ($rename->getTarget()->getPathname() !== $canonicalTargetPath) {
                    continue;
                }

                $sourcePath     = $rename->getSource()->getPathname();
                $sourceBasename = $rename->getSource()->getBasename(
                    '.' . $rename->getSource()->getExtension()
                );

                $hasLpId   = isset($this->contentIdentifierMap[$sourcePath]);
                $exactName = $sourceBasename === $canonicalTargetBasename;

                if ($canonicalRename === null) {
                    $canonicalRename    = $rename;
                    $canonicalHasLpId   = $hasLpId;
                    $canonicalExactName = $exactName;
                }

                // Priority 1: source already has the canonical base name (idempotency).
                if ($exactName && !$canonicalExactName) {
                    $canonicalRename    = $rename;
                    $canonicalHasLpId   = $hasLpId;
                    $canonicalExactName = true;

                    break;
                }

                // Priority 2: file has a Live Photo content ID (original capture).
                if ($hasLpId && !$canonicalHasLpId && !$canonicalExactName) {
                    $canonicalRename  = $rename;
                    $canonicalHasLpId = true;
                }
            }

            // If another file in the group (any extension) already occupies the
            // unsuffixed base name, the canonical does not need promotion — the
            // base name is already taken by a different extension variant.
            $canonicalNeedsPromotion = true;

            if ($canonicalRename instanceof Rename && !$canonicalExactName) {
                foreach ($renames as $rename) {
                    if ($rename === $canonicalRename) {
                        continue;
                    }

                    if ($rename->getSource()->getPathname() === $rename->getTarget()->getPathname()) {
                        // A file with base name already exists (source == target).
                        $canonicalNeedsPromotion = false;

                        break;
                    }
                }
            }

            $renames->reindex();
            $fileDuplicate->setRenames($renames);

            // Detect Live Photo companion: in a live-photo group, exactly one file
            // of a different media type (e.g. MOV for a JPG canonical) should receive
            // the same base name without a duplicate suffix.
            $companionRename = $this->detectLivePhotoCompanion(
                $canonicalRename,
                $fileDuplicate,
            );

            // Content-hash sub-grouping: when multiple distinct files share the
            // same target name, assign sequential numbers per unique content hash.
            if (
                !$skipHashSubGrouping
                && $this->hashSubGroupingService->apply(
                    $fileDuplicate,
                    $canonicalRename,
                    $companionRename,
                    $this->contentIdentifierMap,
                    $this->getTargetPathname(...),
                )
            ) {
                ++$this->namingCollisions;
                $progressBar->advance();

                continue;
            }

            // Per-extension duplicate counters — each extension gets independent sequential numbering
            // (e.g., -duplicate-001.jpg and -duplicate-001.mov can coexist in the same group).
            /** @var array<string, int> $duplicateCountByExtension */
            $duplicateCountByExtension = [];
            $duplicateEntries          = 0;

            // Collect source paths of all files in this group so that on-disk collisions
            // with group members (who will be moved) are treated as available, and count
            // non-canonical/non-companion entries in a single pass.
            $groupSourcePaths = [];

            foreach ($fileDuplicate->getRenames() as $rename) {
                $groupSourcePaths[$rename->getSource()->getPathname()] = true;

                if ($canonicalRename instanceof Rename && $rename === $canonicalRename) {
                    continue;
                }

                if ($companionRename instanceof Rename && $rename === $companionRename) {
                    continue;
                }

                ++$duplicateEntries;
            }

            $hasAdditionalRenames = $duplicateEntries > 0;
            $processedDuplicates  = 0;

            // Assign unique target filenames to remaining renames.

            foreach ($fileDuplicate->getRenames() as $rename) {
                $isCanonicalRename = $canonicalRename instanceof Rename
                    && $rename === $canonicalRename
                    && $canonicalNeedsPromotion;
                $isCompanionRename = $companionRename instanceof Rename && $rename === $companionRename;

                // Live Photo companions are treated like canonicals: same base name, no suffix.
                if ($isCompanionRename) {
                    ++$processedDuplicates;

                    continue;
                }

                $ext = strtolower($rename->getTarget()->getExtension());

                if (!isset($duplicateCountByExtension[$ext])) {
                    $duplicateCountByExtension[$ext] = 1;
                }

                if ($isCanonicalRename) {
                    $rename->setTarget(
                        $this->resolveCanonicalTarget(
                            $rename->getSource(),
                            $rename->getTarget(),
                            $duplicateCountByExtension[$ext],
                            $groupSourcePaths,
                        )
                    );
                } else {
                    // Non-canonical files sharing the canonical target path need a suffix.
                    // When the canonical itself doesn't need promotion (base name already
                    // occupied by another extension), it also needs disambiguation.
                    $requiresCanonicalDisambiguation = $canonicalRename instanceof Rename
                        && ($rename !== $canonicalRename || !$canonicalNeedsPromotion)
                        && $rename->getTarget()->getPathname() === $canonicalTargetPath;

                    $rename->setTarget(
                        $this->createDuplicateTargetFileInfo(
                            $rename->getSource(),
                            $rename->getTarget(),
                            $duplicateCountByExtension[$ext],
                            $processedDuplicates === 0,
                            $hasAdditionalRenames,
                            $requiresCanonicalDisambiguation,
                            $groupSourcePaths,
                        )
                    );
                }

                ++$processedDuplicates;
            }

            if ($canonicalRename instanceof Rename && $canonicalNeedsPromotion) {
                $fileDuplicate->setTarget($canonicalRename->getTarget());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Creates and starts a Symfony progress bar tailored to the current workload.
     *
     * @param int $max maximum number of steps the progress bar should represent
     *
     * @return ProgressBar configured progress bar instance ready for updates
     */
    private function startProgressBar(int $max): ProgressBar
    {
        $progressBar = $this->io->createProgressBar(max($max, 1));
        $progressBar->setFormat(FileSystemService::PROGRESS_BAR_FORMAT);
        $progressBar->start();

        return $progressBar;
    }

    /**
     * Resolves the target for the canonical file in a duplicate group.
     *
     * The canonical file keeps the unsuffixed base name when possible.
     * When the target is already occupied by a file outside the group,
     * a unique duplicate suffix is assigned instead.
     *
     * @param SplFileInfo         $source           source file currently being processed
     * @param SplFileInfo         $target           initial target file information
     * @param int                 $duplicateCount   counter used to create unique duplicate suffixes (passed by reference)
     * @param array<string, true> $groupSourcePaths source paths of all files in the current group
     *
     * @return SplFileInfo file information pointing to the deduplicated target
     */
    private function resolveCanonicalTarget(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        array $groupSourcePaths,
    ): SplFileInfo {
        // File already at its target path — no rename needed (idempotency).
        if ($target->getPathname() === $source->getPathname()) {
            return $target;
        }

        // Canonical files get the unsuffixed base name when available.
        if (!$this->isTargetOccupied($target, $source, $groupSourcePaths)) {
            return $target;
        }

        return $this->getNewUniqueDuplicateTargetFileInfo(
            $source,
            $target,
            $target->getBasename('.' . $target->getExtension()),
            $duplicateCount,
            false,
            $groupSourcePaths,
        );
    }

    /**
     * Resolves the target file information for a duplicate, ensuring unique filenames.
     *
     * @param SplFileInfo         $source                          source file currently being processed
     * @param SplFileInfo         $target                          initial target file information
     * @param int                 $duplicateCount                  counter used to create unique duplicate suffixes (passed by reference)
     * @param bool                $isFirst                         whether the file is the first item within the duplicate group
     * @param bool                $hasAdditionalRenames            whether the group has more than one non-canonical rename
     * @param bool                $requiresCanonicalDisambiguation whether the file shares the canonical target path
     * @param array<string, true> $groupSourcePaths                source paths of all files in the current group
     *
     * @return SplFileInfo file information pointing to the deduplicated target
     */
    private function createDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        bool $isFirst = false,
        bool $hasAdditionalRenames = false,
        bool $requiresCanonicalDisambiguation = false,
        array $groupSourcePaths = [],
    ): SplFileInfo {
        // File already at its target path — no rename needed (idempotency).
        if ($target->getPathname() === $source->getPathname()) {
            return $target;
        }

        $duplicateBasename = $target->getBasename('.' . $target->getExtension());

        if ($this->isTargetOccupied($target, $source, $groupSourcePaths)) {
            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount,
                $requiresCanonicalDisambiguation,
                $groupSourcePaths,
            );
        }

        if (!$isFirst) {
            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount,
                $hasAdditionalRenames || $requiresCanonicalDisambiguation,
                $groupSourcePaths,
            );
        }

        if ($hasAdditionalRenames || $requiresCanonicalDisambiguation) {
            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount,
                $requiresCanonicalDisambiguation,
                $groupSourcePaths,
            );
        }

        // Single file without additional renames: keep as-is.
        return $target;
    }

    /**
     * Generates a target file info whose path does not collide with any existing file on disk.
     * Increments the duplicate counter in a loop until a free slot is found, or reuses the
     * source path for idempotent re-runs.
     *
     * @param SplFileInfo         $source               source file currently being processed
     * @param SplFileInfo         $target               initial target file information
     * @param string              $targetBasename       base filename (without extension) used for duplicate naming
     * @param int                 $duplicateCount       counter used to create unique duplicate suffixes (passed by reference)
     * @param bool                $forceDuplicateSuffix when true, always apply a suffix even if the target is free
     * @param array<string, true> $groupSourcePaths     source paths of all files in the current group
     *
     * @return SplFileInfo file info pointing to a non-occupied target path
     */
    private function getNewUniqueDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
        bool $forceDuplicateSuffix = false,
        array $groupSourcePaths = [],
    ): SplFileInfo {
        $duplicateFileInfo = $target;

        if ($forceDuplicateSuffix) {
            $duplicateFileInfo = $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $targetBasename,
                $duplicateCount
            );

            ++$duplicateCount;

            if ($duplicateFileInfo->getPathname() === $source->getPathname()) {
                return $duplicateFileInfo;
            }
        }

        while ($this->isTargetOccupied($duplicateFileInfo, $source, $groupSourcePaths)) {
            if ($duplicateCount > FileSystemService::MAX_DUPLICATE_SUFFIX) {
                throw new RuntimeException(
                    sprintf('Exceeded %d duplicate suffix attempts', FileSystemService::MAX_DUPLICATE_SUFFIX)
                );
            }

            $duplicateFileInfo = $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $targetBasename,
                $duplicateCount
            );

            ++$duplicateCount;

            // Idempotency: if a generated duplicate target (with suffix) matches the source,
            // the file already has the correct name from a previous run.
            if ($duplicateFileInfo->getPathname() === $source->getPathname()) {
                break;
            }
        }

        return $duplicateFileInfo;
    }

    /**
     * Returns a new file info object with a unique filename.
     *
     * @param SplFileInfo $source         source file currently being processed
     * @param SplFileInfo $target         initial target file information
     * @param string      $targetBasename base filename (without extension) used for duplicate naming
     * @param int         $duplicateCount counter used to create unique duplicate suffixes (passed by reference)
     *
     * @return SplFileInfo file info representing the next duplicate candidate
     */
    private function getNewDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
    ): SplFileInfo {
        $newTargetBasename = sprintf(
            '%s' . FileSystemService::DUPLICATE_IDENTIFIER . '%003d',
            $targetBasename,
            $duplicateCount
        );

        $targetPathname = $this->getTargetPathname(
            $source,
            $newTargetBasename . '.' . $target->getExtension()
        );

        return new SplFileInfo($targetPathname);
    }

    /**
     * Builds the target pathname for a source file and generated filename.
     *
     * @param SplFileInfo $sourceFileInfo source file for which the target path should be computed
     * @param string      $targetFilename filename (without directory) to use in the destination
     *
     * @return string absolute pathname pointing to the intended target location
     */
    public function getTargetPathname(SplFileInfo $sourceFileInfo, string $targetFilename): string
    {
        if (str_contains($targetFilename, DIRECTORY_SEPARATOR) || str_contains($targetFilename, '/')) {
            throw new RuntimeException(
                sprintf('Target filename "%s" must not contain directory separators', $targetFilename)
            );
        }

        $sourcePath   = $sourceFileInfo->getPath();
        $relativePath = $sourcePath;

        if (str_starts_with($sourcePath, $this->sourceDirectory)) {
            $relativePath = substr($sourcePath, strlen($this->sourceDirectory));
        }

        $relativePath = trim($relativePath, DIRECTORY_SEPARATOR);

        $targetPath = rtrim($this->targetDirectory, DIRECTORY_SEPARATOR);

        if ($relativePath !== '') {
            $targetPath .= DIRECTORY_SEPARATOR . $relativePath;
        }

        return $targetPath . DIRECTORY_SEPARATOR . $targetFilename;
    }

    /**
     * Returns a new target file object for the given source file object.
     *
     * @param SplFileInfo             $sourceFileInfo source file that should be renamed
     * @param RenameStrategyInterface $renameStrategy strategy responsible for generating the target filename
     *
     * @return SplFileInfo|null target file info when the strategy yields a filename, otherwise null
     */
    protected function getTargetFileInfo(SplFileInfo $sourceFileInfo, RenameStrategyInterface $renameStrategy): ?SplFileInfo
    {
        try {
            $targetFilename = $renameStrategy->generateFilename($sourceFileInfo);

            if ($targetFilename !== null) {
                // Create a new target file object
                return new SplFileInfo(
                    $this->getTargetPathname(
                        $sourceFileInfo,
                        $targetFilename
                    )
                );
            }
        } catch (TargetFilenameException $exception) {
            $this->io->error($exception->getMessage());
        }

        return null;
    }

    /**
     * Promotes the group's canonical target to a still image when a Live Photo
     * group's current canonical is a video (MOV) and the candidate is a still (HEIC/JPG).
     * Ensures the still image always takes precedence as the group's base name source.
     *
     * @param string        $duplicateIdentifier Group key (only live-photo: prefixed groups are affected)
     * @param FileDuplicate $fileDuplicate       The duplicate group whose target may be promoted
     * @param SplFileInfo   $candidateTarget     Newly encountered target to consider for promotion
     */
    private function promoteLivePhotoTargetIfNecessary(
        string $duplicateIdentifier,
        FileDuplicate $fileDuplicate,
        SplFileInfo $candidateTarget,
    ): void {
        if (!str_starts_with($duplicateIdentifier, FileSystemService::LIVE_PHOTO_IDENTIFIER_PREFIX)) {
            return;
        }

        if (!$this->hashSubGroupingService->isLivePhotoStill($candidateTarget)) {
            return;
        }

        if ($this->hashSubGroupingService->isLivePhotoStill($fileDuplicate->getTarget())) {
            return;
        }

        $fileDuplicate->setTarget($candidateTarget);
    }

    /**
     * Identifies the Live Photo companion rename within a duplicate group.
     *
     * Uses the content identifier map to find a file that shares the canonical's
     * Live Photo content ID but has a different media type (e.g. MOV for a JPG canonical).
     *
     * @return Rename|null the companion rename, or null if no companion was found
     */
    private function detectLivePhotoCompanion(
        ?Rename $canonicalRename,
        FileDuplicate $fileDuplicate,
    ): ?Rename {
        if (!$canonicalRename instanceof Rename) {
            return null;
        }

        $canonicalPath      = $canonicalRename->getSource()->getPathname();
        $canonicalContentId = $this->contentIdentifierMap[$canonicalPath] ?? null;

        if ($canonicalContentId === null) {
            return null;
        }

        $canonicalIsStill = $this->hashSubGroupingService->isLivePhotoStill($canonicalRename->getSource());

        foreach ($fileDuplicate->getRenames() as $rename) {
            if ($rename === $canonicalRename) {
                continue;
            }

            $renamePath      = $rename->getSource()->getPathname();
            $renameContentId = $this->contentIdentifierMap[$renamePath] ?? null;

            // Companion must share the canonical's content ID and be a different media type.
            if ($renameContentId !== $canonicalContentId) {
                continue;
            }

            $renameIsStill = $this->hashSubGroupingService->isLivePhotoStill($rename->getSource());

            if ($canonicalIsStill !== $renameIsStill) {
                return $rename;
            }
        }

        return null;
    }

    /**
     * Checks whether the target path is already occupied by a file that is NOT the
     * source itself and NOT another member of the same group (who will be moved away).
     * Uses the in-memory disk index for fast lookups, falling back to stat() for paths
     * outside the scanned directories.
     *
     * @param SplFileInfo         $target           Target path to check
     * @param SplFileInfo         $source           Source file being processed (never considered occupied)
     * @param array<string, true> $groupSourcePaths Source paths of all files in the current group
     */
    private function isTargetOccupied(SplFileInfo $target, SplFileInfo $source, array $groupSourcePaths): bool
    {
        $targetPath = $target->getPathname();

        // Target is the source itself — not occupied.
        if ($targetPath === $source->getPathname()) {
            return false;
        }

        // Fast path: target is known from the scan index → exists.
        // Fallback: stat() for paths outside the scanned directories.
        if (!isset($this->diskIndex[$targetPath]) && !$target->isFile()) {
            return false;
        }

        // Target exists but belongs to another file in the same group — will be freed.
        // Target exists and belongs to an external file — occupied.
        return !isset($groupSourcePaths[$targetPath]);
    }

    /**
     * Extracts and normalizes the Live Photo content identifier from the rename strategy,
     * returning null when the strategy does not support Live Photo awareness or when the
     * file has no content identifier.
     *
     * @param RenameStrategyInterface $renameStrategy Strategy that may implement LivePhotoAwareRenameStrategyInterface
     * @param SplFileInfo             $sourceFileInfo File to extract the content identifier from
     *
     * @return string|null Lowercased, trimmed content identifier, or null
     */
    private function resolveNormalizedContentIdentifier(
        RenameStrategyInterface $renameStrategy,
        SplFileInfo $sourceFileInfo,
    ): ?string {
        if (!$renameStrategy instanceof LivePhotoAwareRenameStrategyInterface) {
            return null;
        }

        $contentIdentifier = $renameStrategy->getLivePhotoContentIdentifier($sourceFileInfo);

        if (!is_string($contentIdentifier)) {
            return null;
        }

        $normalized = strtolower(trim($contentIdentifier));

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }
}
