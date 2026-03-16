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

    /**
     * Constructor.
     */
    public function __construct(
        private readonly FileSystemService $fileSystemService,
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
        $progressBar = $this->startProgressBar(
            $this->fileSystemService->countFiles($iterator)
        );

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

        /** @var SplFileInfo $sourceFileInfo */
        foreach ($iterator as $sourceFileInfo) {
            // The resulting file object
            $targetFileInfo = $this->getTargetFileInfo(
                $sourceFileInfo,
                $renameStrategy
            );

            $normalizedContentIdentifier = $this->resolveNormalizedContentIdentifier(
                $renameStrategy,
                $sourceFileInfo,
            );

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
    ): FileDuplicateCollection
    {
        $progressBar = $this->startProgressBar($fileDuplicateCollection->count());

        /** @var string $duplicateIdentifier */
        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $duplicateIdentifier => $fileDuplicate) {
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

            $canonicalTargetPath = $fileDuplicate->getTarget()->getPathname();
            /** @var Rename|null $canonicalRename */
            $canonicalRename = null;

            foreach ($renames as $rename) {
                if ($rename->getTarget()->getPathname() === $canonicalTargetPath) {
                    $canonicalRename = $rename;

                    break;
                }
            }

            $renames->reindex();
            $fileDuplicate->setRenames($renames);

            // Detect Live Photo companion: in a live-photo group, exactly one file
            // of a different media type (e.g. MOV for a JPG canonical) should receive
            // the same base name without a duplicate suffix.
            $companionRename = $this->detectLivePhotoCompanion(
                $duplicateIdentifier,
                $canonicalRename,
                $fileDuplicate,
            );

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

            // Pre-assign targets for files that already have a correct duplicate suffix
            // from a previous run (idempotency). Process these first so their suffix
            // numbers are reserved before new duplicates are assigned.
            $this->preAssignExistingDuplicateSuffixes(
                $fileDuplicate,
                $canonicalRename,
                $companionRename,
                $duplicateCountByExtension,
            );

            // Assign unique target filenames to remaining renames.
            /** @var array<int, true> $preAssigned */
            $preAssigned = [];

            foreach ($fileDuplicate->getRenames() as $index => $rename) {
                if ($rename->getSource()->getPathname() === $rename->getTarget()->getPathname()
                    && str_contains($rename->getTarget()->getFilename(), FileSystemService::DUPLICATE_IDENTIFIER)
                ) {
                    $preAssigned[$index] = true;
                }
            }

            foreach ($fileDuplicate->getRenames() as $index => $rename) {
                $isCanonicalRename = $canonicalRename instanceof Rename && $rename === $canonicalRename;
                $isCompanionRename = $companionRename instanceof Rename && $rename === $companionRename;

                // Live Photo companions are treated like canonicals: same base name, no suffix.
                if ($isCompanionRename) {
                    ++$processedDuplicates;

                    continue;
                }

                // Already pre-assigned (idempotency).
                if (isset($preAssigned[$index])) {
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

        // Canonical files may keep their current name even if the file exists on disk.
        if ($isCanonicalRename && $target->getPathname() === $source->getPathname()) {
            return $target;
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
     * A companion is the first file of a different media type (e.g. MOV for a JPG canonical)
     * in a live-photo group. It should receive the same base name as the canonical without
     * a duplicate suffix.
     *
     * @return Rename|null the companion rename, or null if the group is not a live-photo group
     *                     or no companion was found
     */
    private function detectLivePhotoCompanion(
        string $duplicateIdentifier,
        ?Rename $canonicalRename,
        FileDuplicate $fileDuplicate,
    ): ?Rename {
        if (!str_starts_with($duplicateIdentifier, self::LIVE_PHOTO_IDENTIFIER_PREFIX)) {
            return null;
        }

        if (!$canonicalRename instanceof Rename) {
            return null;
        }

        $canonicalIsStill = $this->isLivePhotoStill($canonicalRename->getSource());

        foreach ($fileDuplicate->getRenames() as $rename) {
            if ($rename === $canonicalRename) {
                continue;
            }

            // Companion must be a different media type than the canonical.
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
     * Determines whether a target path is occupied by a file that is NOT part of the current group.
     * Files within the group will be moved away during renaming, so their paths are available.
     *
     * @param SplFileInfo         $target           target file info to check
     * @param SplFileInfo         $source           source file being renamed
     * @param array<string, true> $groupSourcePaths source paths of all files in the current group
     */
    /**
     * Pre-assigns targets for files that already carry a correct duplicate suffix.
     *
     * For idempotency: if a source file is already named `photo-duplicate-001.jpg`
     * and its expected base name is `photo`, we keep it and reserve suffix 001.
     *
     * @param array<string, int> $duplicateCountByExtension suffix counters per extension (modified by reference)
     */
    private function preAssignExistingDuplicateSuffixes(
        FileDuplicate $fileDuplicate,
        ?Rename $canonicalRename,
        ?Rename $companionRename,
        array &$duplicateCountByExtension,
    ): void {
        $canonicalBasename = $fileDuplicate->getTarget()->getBasename(
            '.' . $fileDuplicate->getTarget()->getExtension()
        );

        $pattern = '/^' . preg_quote($canonicalBasename, '/') . preg_quote(FileSystemService::DUPLICATE_IDENTIFIER, '/') . '(\d+)$/';

        foreach ($fileDuplicate->getRenames() as $rename) {
            if ($canonicalRename instanceof Rename && $rename === $canonicalRename) {
                continue;
            }

            if ($companionRename instanceof Rename && $rename === $companionRename) {
                continue;
            }

            $sourceBasename = $rename->getSource()->getBasename('.' . $rename->getSource()->getExtension());

            if (preg_match($pattern, $sourceBasename, $matches) !== 1) {
                continue;
            }

            $ext          = strtolower($rename->getSource()->getExtension());
            $suffixNumber = (int) $matches[1];

            // Reserve this suffix number so new duplicates skip it.
            if (!isset($duplicateCountByExtension[$ext]) || $duplicateCountByExtension[$ext] <= $suffixNumber) {
                $duplicateCountByExtension[$ext] = $suffixNumber + 1;
            }

            // Set the target to the source path (keep current name).
            $rename->setTarget($rename->getSource());
        }
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

        // Target doesn't exist on disk — not occupied.
        if (!$target->isFile()) {
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
