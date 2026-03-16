<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_key_exists;
use function count;
use function in_array;
use function is_string;
use function method_exists;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Service for duplicate detection operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class DuplicateDetectionService implements DuplicateDetectionServiceInterface
{
    private const string LIVE_PHOTO_IDENTIFIER_PREFIX = 'live-photo:';

    /**
     * Extensions that identify still image assets within Live Photo groups.
     *
     * @var array<int, string>
     */
    private const array LIVE_PHOTO_STILL_EXTENSIONS = ['heic', 'heif', 'jpg', 'jpeg'];

    private string $sourceDirectory;

    private string $targetDirectory;

    private bool $useFileExtensionFromSource = false;

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
     * Constructor.
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly SafeHashCalculator $hashCalculator,
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

    public function setListAll(bool $listAll): DuplicateDetectionService
    {
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

        usort($files, static function (SplFileInfo $a, SplFileInfo $b): int {
            $depthA = substr_count($a->getPath(), DIRECTORY_SEPARATOR);
            $depthB = substr_count($b->getPath(), DIRECTORY_SEPARATOR);

            return $depthA !== $depthB
                ? $depthA - $depthB
                : strcmp($a->getPathname(), $b->getPathname());
        });

        // Build in-memory disk index to avoid stat() calls during planning.
        $this->diskIndex            = [];
        $this->contentIdentifierMap = [];

        foreach ($files as $file) {
            $this->diskIndex[$file->getPathname()] = true;
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

                if (isset($contentIdentifierCacheEntry)) {
                    unset($contentIdentifierCacheEntry);
                }

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

                if (isset($contentIdentifierCacheEntry)) {
                    unset($contentIdentifierCacheEntry);
                }

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

            if (isset($contentIdentifierCacheEntry)) {
                unset($contentIdentifierCacheEntry);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Creates consecutive filenames for duplicate files in the supplied collection.
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
                && $this->applyHashSubGrouping($fileDuplicate, $canonicalRename, $companionRename)
            ) {
                $progressBar->advance();

                continue;
            }

            /** @var array<string, int> $duplicateCountByExtension */
            $duplicateCountByExtension = [];
            $duplicateEntries          = 0;

            foreach ($fileDuplicate->getRenames() as $rename) {
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

            // Collect source paths of all files in this group so that on-disk collisions
            // with group members (who will be moved) are treated as available.
            $groupSourcePaths = [];

            foreach ($fileDuplicate->getRenames() as $rename) {
                $groupSourcePaths[$rename->getSource()->getPathname()] = true;
            }

            // Assign unique target filenames to remaining renames.

            foreach ($fileDuplicate->getRenames() as $rename) {
                $isCanonicalRename = $canonicalRename instanceof Rename && $rename === $canonicalRename;
                $isCompanionRename = $companionRename instanceof Rename && $rename === $companionRename;

                // Live Photo companions are treated like canonicals: same base name, no suffix.
                if ($isCompanionRename) {
                    ++$processedDuplicates;

                    continue;
                }

                $requiresCanonicalDisambiguation = ($canonicalRename instanceof Rename && $rename !== $canonicalRename)
                    && $rename->getTarget()->getPathname() === $canonicalTargetPath;

                $ext = strtolower($rename->getTarget()->getExtension());

                if (!isset($duplicateCountByExtension[$ext])) {
                    $duplicateCountByExtension[$ext] = 1;
                }

                $rename->setTarget(
                    $this->createDuplicateTargetFileInfo(
                        $rename->getSource(),
                        $rename->getTarget(),
                        $duplicateCountByExtension[$ext],
                        $processedDuplicates === 0,
                        $hasAdditionalRenames,
                        $requiresCanonicalDisambiguation,
                        $isCanonicalRename,
                        $groupSourcePaths,
                    )
                );

                ++$processedDuplicates;
            }

            if ($canonicalRename instanceof Rename) {
                $fileDuplicate->setTarget($canonicalRename->getTarget());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Applies content-hash sub-grouping when a naming conflict exists.
     *
     * Returns true when sub-grouping was applied (multiple distinct hashes found),
     * in which case the caller should skip the default suffix assignment.
     * Returns false when sub-grouping is not needed (single file, single hash group,
     * or only companions).
     */
    private function applyHashSubGrouping(
        FileDuplicate $fileDuplicate,
        ?Rename $canonicalRename,
        ?Rename $companionRename,
    ): bool {
        /** @var list<Rename> $nonCompanionRenames */
        $nonCompanionRenames = [];

        // In Live Photo groups, exclude ALL files of the companion's media type
        // (e.g., all MOVs when the companion is a MOV), not just the single
        // detected companion. This prevents video hashes from triggering
        // false naming conflicts with still image hashes.
        $excludeStills = $companionRename instanceof Rename
            && $this->isLivePhotoStill($companionRename->getSource());

        foreach ($fileDuplicate->getRenames() as $rename) {
            if ($companionRename instanceof Rename) {
                $renameIsStill = $this->isLivePhotoStill($rename->getSource());

                // Exclude files that share the companion's media type.
                if ($excludeStills === $renameIsStill) {
                    continue;
                }
            }

            $nonCompanionRenames[] = $rename;
        }

        // No sub-grouping needed for 0 or 1 non-companion files.
        if (count($nonCompanionRenames) <= 1) {
            return false;
        }

        // Compute hashes and build sub-groups keyed by hash.
        /** @var array<string, list<Rename>> $hashGroups */
        $hashGroups = [];

        /** @var array<string, string> $renameToHash Map from source pathname to hash */
        $renameToHash = [];

        $uniqueHashCounter = 0;

        foreach ($nonCompanionRenames as $rename) {
            $sourcePath = $rename->getSource()->getPathname();

            try {
                $hash = $this->hashCalculator->hashFile($rename->getSource(), 'xxh128');
            } catch (HashComputationException $exception) {
                $this->io->error($exception->getMessage());

                // Treat as unique hash (own sub-group).
                $hash = '__failed_' . $uniqueHashCounter;
                ++$uniqueHashCounter;
            }

            $renameToHash[$sourcePath] = $hash;

            if (!isset($hashGroups[$hash])) {
                $hashGroups[$hash] = [];
            }

            $hashGroups[$hash][] = $rename;
        }

        // If all files have the same hash, this is a pure duplicate group.
        // Fall through to existing logic.
        if (count($hashGroups) <= 1) {
            return false;
        }

        // Multiple hashes: naming conflict. The canonical's sub-group keeps the
        // unsuffixed base name; other sub-groups get sequential numbers starting at 002.
        $canonicalBasename = $fileDuplicate->getTarget()->getBasename(
            '.' . $fileDuplicate->getTarget()->getExtension()
        );

        // Determine which hash group contains the canonical.
        $canonicalHash = null;

        if ($canonicalRename instanceof Rename) {
            $canonicalHash = $renameToHash[$canonicalRename->getSource()->getPathname()] ?? null;
        }

        // Assign sub-group numbers: canonical's hash gets 0 (no suffix), others get 2, 3, ...
        $subGroupNumber = 2;

        /** @var list<Rename> $newRenames */
        $newRenames = [];

        /** @var array<string, int> $hashToSubGroup Map from hash to sub-group number (0 = canonical group) */
        $hashToSubGroup = [];

        // Process canonical's hash group first (no sub-group number).
        if ($canonicalHash !== null && isset($hashGroups[$canonicalHash])) {
            $hashToSubGroup[$canonicalHash] = 0;
        }

        foreach (array_keys($hashGroups) as $hash) {
            if ($hash === $canonicalHash) {
                continue;
            }

            $hashToSubGroup[$hash] = $subGroupNumber;
            ++$subGroupNumber;
        }

        // Now process all hash groups in their assigned order.
        foreach ($hashGroups as $hash => $groupRenames) {
            $groupNumber      = $hashToSubGroup[$hash];
            $isCanonicalGroup = $groupNumber === 0;

            $subGroupBasename = $isCanonicalGroup
                ? $canonicalBasename
                : sprintf('%s-%03d', $canonicalBasename, $groupNumber);

            $duplicateIndex = 1;

            foreach ($groupRenames as $rename) {
                $ext = strtolower($rename->getTarget()->getExtension());

                // In the canonical group, the actual canonical rename gets no suffix.
                // In other groups, the first file gets no suffix (sub-group canonical).
                $isSubGroupCanonical = $isCanonicalGroup
                    ? ($canonicalRename instanceof Rename && $rename === $canonicalRename)
                    : ($duplicateIndex === 1 && $rename === $groupRenames[0]);

                if ($isSubGroupCanonical) {
                    // Sub-group canonical: no duplicate suffix.
                    $newTargetFilename = $subGroupBasename . '.' . $ext;
                } else {
                    // Duplicate within this sub-group.
                    $newTargetFilename = sprintf(
                        '%s%s%03d.%s',
                        $subGroupBasename,
                        FileSystemService::DUPLICATE_IDENTIFIER,
                        $duplicateIndex,
                        $ext,
                    );

                    ++$duplicateIndex;
                }

                $targetPathname = $this->getTargetPathname($rename->getSource(), $newTargetFilename);

                $rename->setTarget(new SplFileInfo($targetPathname));
                $newRenames[] = $rename;
            }
        }

        // Handle excluded files (companion media type): each inherits the sub-group
        // number of its Live Photo pair's canonical (via content ID lookup).
        // The first excluded file per LP content ID is the companion (no suffix),
        // additional files of the same content ID are duplicates.
        /** @var array<string, int> $excludedDuplicateCountByContentId */
        $excludedDuplicateCountByContentId = [];

        foreach ($fileDuplicate->getRenames() as $rename) {
            // Skip files already processed as non-companion (stills).
            if (in_array($rename, $nonCompanionRenames, true)) {
                continue;
            }

            // Determine which sub-group this excluded file belongs to via content ID.
            $renamePath      = $rename->getSource()->getPathname();
            $renameContentId = $this->contentIdentifierMap[$renamePath] ?? null;

            $subGroupNum = 0; // default: canonical group (no suffix)

            if ($renameContentId !== null) {
                // Find the still file with the same content ID to determine its hash sub-group.
                foreach ($nonCompanionRenames as $stillRename) {
                    $stillPath      = $stillRename->getSource()->getPathname();
                    $stillContentId = $this->contentIdentifierMap[$stillPath] ?? null;

                    if ($stillContentId === $renameContentId) {
                        $stillHash   = $renameToHash[$stillPath] ?? null;
                        $subGroupNum = ($stillHash !== null && isset($hashToSubGroup[$stillHash]))
                            ? $hashToSubGroup[$stillHash]
                            : 0;

                        break;
                    }
                }
            }

            $fileBasename = $subGroupNum === 0
                ? $canonicalBasename
                : sprintf('%s-%03d', $canonicalBasename, $subGroupNum);

            $ext = strtolower($rename->getTarget()->getExtension());

            // First file per content ID is the companion (no duplicate suffix).
            $contentIdKey = $renameContentId ?? '__none_' . $renamePath;

            if (!isset($excludedDuplicateCountByContentId[$contentIdKey])) {
                $excludedDuplicateCountByContentId[$contentIdKey] = 1;
                $newTargetFilename                                = $fileBasename . '.' . $ext;
            } else {
                $dupIdx            = $excludedDuplicateCountByContentId[$contentIdKey];
                $newTargetFilename = sprintf(
                    '%s%s%03d.%s',
                    $fileBasename,
                    FileSystemService::DUPLICATE_IDENTIFIER,
                    $dupIdx,
                    $ext,
                );

                ++$excludedDuplicateCountByContentId[$contentIdKey];
            }

            $targetPathname = $this->getTargetPathname($rename->getSource(), $newTargetFilename);
            $rename->setTarget(new SplFileInfo($targetPathname));
            $newRenames[] = $rename;
        }

        // Replace the renames in the fileDuplicate with the newly ordered list.
        $fileDuplicate->setRenames(new RenameList($newRenames));

        // Update the group's canonical target to match the first sub-group's canonical.
        if ($canonicalRename instanceof Rename) {
            $fileDuplicate->setTarget($canonicalRename->getTarget());
        }

        ++$this->namingCollisions;

        return true;
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
     * Resolves the target file information for a duplicate, ensuring unique filenames.
     *
     * @param SplFileInfo $source         source file currently being processed
     * @param SplFileInfo $target         initial target file information
     * @param int         $duplicateCount counter used to create unique duplicate suffixes (passed by reference)
     * @param bool        $isFirst        whether the file is the first item within the duplicate group
     *
     * @return SplFileInfo file information pointing to the deduplicated target
     */
    /**
     * @param array<string, true> $groupSourcePaths source paths of all files in the current group
     */
    private function createDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        bool $isFirst = false,
        bool $hasAdditionalRenames = false,
        bool $requiresCanonicalDisambiguation = false,
        bool $isCanonicalRename = false,
        array $groupSourcePaths = [],
    ): SplFileInfo {
        $duplicateBasename = $target->getBasename('.' . $target->getExtension());

        // Canonical files get the unsuffixed base name when available.
        if ($isCanonicalRename) {
            if (!$this->isTargetOccupied($target, $source, $groupSourcePaths)) {
                return $target;
            }

            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount,
                $requiresCanonicalDisambiguation,
                $groupSourcePaths,
            );
        }

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

        // Canonical without additional renames: keep as-is.
        return $target;
    }

    /**
     * Generates a new target file info instance whose path does not yet exist on disk.
     *
     * @param SplFileInfo $source         source file currently being processed
     * @param SplFileInfo $target         initial target file information
     * @param string      $targetBasename base filename (without extension) used for duplicate naming
     * @param int         $duplicateCount counter used to create unique duplicate suffixes (passed by reference)
     *
     * @return SplFileInfo newly generated file info pointing to a non-existing file
     */
    /**
     * @param array<string, true> $groupSourcePaths source paths of all files in the current group
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

    private function promoteLivePhotoTargetIfNecessary(
        string $duplicateIdentifier,
        FileDuplicate $fileDuplicate,
        SplFileInfo $candidateTarget,
    ): void {
        if (!str_starts_with($duplicateIdentifier, self::LIVE_PHOTO_IDENTIFIER_PREFIX)) {
            return;
        }

        if (!$this->isLivePhotoStill($candidateTarget)) {
            return;
        }

        if ($this->isLivePhotoStill($fileDuplicate->getTarget())) {
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

        $canonicalIsStill = $this->isLivePhotoStill($canonicalRename->getSource());

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

            $renameIsStill = $this->isLivePhotoStill($rename->getSource());

            if ($canonicalIsStill !== $renameIsStill) {
                return $rename;
            }
        }

        return null;
    }

    private function isLivePhotoStill(SplFileInfo $fileInfo): bool
    {
        $extension = strtolower($fileInfo->getExtension());

        if ($extension === '') {
            return false;
        }

        return in_array($extension, self::LIVE_PHOTO_STILL_EXTENSIONS, true);
    }

    /**
     * @param array<string, true> $groupSourcePaths
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

    private function resolveNormalizedContentIdentifier(
        RenameStrategyInterface $renameStrategy,
        SplFileInfo $sourceFileInfo,
    ): ?string {
        if (!method_exists($renameStrategy, 'getLivePhotoContentIdentifier')) {
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
