<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use Closure;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use Override;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_keys;
use function count;
use function spl_object_id;
use function sprintf;
use function strtolower;

/**
 * Applies content-hash based sub-grouping to a duplicate group.
 *
 * When multiple files share the same target name but have distinct content,
 * this service assigns sequential sub-group numbers per unique content hash.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class HashSubGroupingService implements HashSubGroupingServiceInterface
{
    /**
     * @param SafeHashCalculator           $hashCalculator      Computes file content hashes for sub-group keying
     * @param SymfonyStyle                 $io                  Console IO for error output on hash computation failures
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Classifies files as still or video
     */
    public function __construct(
        private SafeHashCalculator $hashCalculator,
        private SymfonyStyle $io,
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
    }

    /**
     * Applies content-hash sub-grouping when a naming conflict exists.
     *
     * Returns true when sub-grouping was applied (multiple distinct hashes found),
     * in which case the caller should skip the default suffix assignment.
     * Returns false when sub-grouping is not needed (single file, single hash group,
     * or only companions).
     *
     * @param FileDuplicate                        $fileDuplicate          the duplicate group to process
     * @param Rename|null                          $canonicalRename        the canonical rename entry
     * @param Rename|null                          $companionRename        the Live Photo companion rename entry
     * @param array<string, string>                $contentIdentifierMap   map from source pathname to content identifier
     * @param Closure(SplFileInfo, string): string $targetPathnameResolver resolves (sourceFileInfo, targetFilename) to absolute target path
     */
    #[Override]
    public function apply(
        FileDuplicate $fileDuplicate,
        ?Rename $canonicalRename,
        ?Rename $companionRename,
        array $contentIdentifierMap,
        Closure $targetPathnameResolver,
    ): bool {
        /** @var list<Rename> $nonCompanionRenames */
        $nonCompanionRenames = [];

        // In Live Photo groups, exclude ALL files of the companion's media type
        // (e.g., all MOVs when the companion is a MOV), not just the single
        // detected companion. This prevents video hashes from triggering
        // false naming conflicts with still image hashes.
        $excludeStills = $companionRename instanceof Rename
            && $this->mediaTypeClassifier->isLivePhotoStill($companionRename->getSource());

        foreach ($fileDuplicate->getRenames() as $rename) {
            if ($companionRename instanceof Rename) {
                $renameIsStill = $this->mediaTypeClassifier->isLivePhotoStill($rename->getSource());

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

        /** @var array<int, true> $nonCompanionLookup */
        $nonCompanionLookup = [];

        foreach ($nonCompanionRenames as $r) {
            $nonCompanionLookup[spl_object_id($r)] = true;
        }

        // Heuristic 1: If all companion videos share the same hash, the stills
        // are semantic duplicates (same capture, different JPG encoding/metadata).
        if ($companionRename instanceof Rename) {
            $companionHashes = [];

            foreach ($fileDuplicate->getRenames() as $rename) {
                if (isset($nonCompanionLookup[spl_object_id($rename)])) {
                    continue;
                }

                try {
                    $hash = $this->hashCalculator->hashFile($rename->getSource(), 'xxh128');
                } catch (HashComputationException) {
                    $hash = null;
                }

                if ($hash !== null) {
                    $companionHashes[$hash] = true;
                }
            }

            if (count($companionHashes) === 1) {
                return false;
            }
        }

        // Multiple hashes: naming conflict. The canonical's sub-group keeps the
        // unsuffixed base name; other sub-groups get sequential numbers starting at 002.
        // Note: the SubSecond semantic duplicate heuristic has been moved to
        // DuplicateDetectionService where it has access to TemporalMetadata software tags.
        $canonicalBasename = FileHelper::basenameWithoutExtension($fileDuplicate->getTarget());

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
        if (($canonicalHash !== null) && isset($hashGroups[$canonicalHash])) {
            $hashToSubGroup[$canonicalHash] = 0;
        }

        foreach (array_keys($hashGroups) as $hash) {
            if ($hash === $canonicalHash) {
                continue;
            }

            $hashToSubGroup[$hash] = $subGroupNumber;
            ++$subGroupNumber;
        }

        // Build a per-directory total file count for cross-directory conflict resolution.
        // A file that is the only member of this group in its directory has no naming
        // conflict there and can keep the unsuffixed canonical basename.
        $canonicalDir = $canonicalRename?->getSource()->getPath();

        /** @var array<string, int> $dirFileCounts directory → total file count */
        $dirFileCounts = [];

        foreach ($nonCompanionRenames as $rename) {
            $dir                 = $rename->getSource()->getPath();
            $dirFileCounts[$dir] = ($dirFileCounts[$dir] ?? 0) + 1;
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
                $ext       = strtolower($rename->getTarget()->getExtension());
                $renameDir = $rename->getSource()->getPath();

                // Cross-directory resolution: a file in a different directory than
                // the canonical that is the only file from this group in its directory
                // has no naming conflict — it keeps the unsuffixed canonical basename.
                $isCrossDirNoConflict = ($renameDir !== $canonicalDir)
                    && !$isCanonicalGroup
                    && (($dirFileCounts[$renameDir] ?? 0) <= 1);

                if ($isCrossDirNoConflict) {
                    $newTargetFilename = $canonicalBasename . '.' . $ext;
                    $targetPathname    = $targetPathnameResolver($rename->getSource(), $newTargetFilename);

                    $rename->setTarget(new SplFileInfo($targetPathname));
                    $newRenames[] = $rename;

                    continue;
                }

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
                        Constants::DUPLICATE_IDENTIFIER,
                        $duplicateIndex,
                        $ext,
                    );

                    ++$duplicateIndex;
                }

                $targetPathname = $targetPathnameResolver(
                    $rename->getSource(),
                    $newTargetFilename,
                );

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

        // Pre-build content-ID -> sub-group map to replace O(n) inner foreach.
        /** @var array<string, int> $contentIdToSubGroup */
        $contentIdToSubGroup = [];

        // Pre-build source filename stem -> sub-group map as fallback for
        // excluded files that lack a content identifier (e.g. MOV companions
        // whose metadata does not include the Live Photo content ID).
        /** @var array<string, int> $sourceBasenameToSubGroup */
        $sourceBasenameToSubGroup = [];

        foreach ($nonCompanionRenames as $stillRename) {
            $stillPath     = $stillRename->getSource()->getPathname();
            $stillHash     = $renameToHash[$stillPath] ?? null;
            $stillSubGroup = ($stillHash !== null && isset($hashToSubGroup[$stillHash]))
                ? $hashToSubGroup[$stillHash]
                : 0;
            $stillContentId = $contentIdentifierMap[$stillPath] ?? null;

            if ($stillContentId !== null) {
                $contentIdToSubGroup[$stillContentId] = $stillSubGroup;
            }

            $stillBasename                            = FileHelper::basenameWithoutExtension($stillRename->getSource());
            $sourceBasenameToSubGroup[$stillBasename] = $stillSubGroup;
        }

        foreach ($fileDuplicate->getRenames() as $rename) {
            // Skip files already processed as non-companion (stills).
            if (isset($nonCompanionLookup[spl_object_id($rename)])) {
                continue;
            }

            // Determine which sub-group this excluded file belongs to via content ID.
            $renamePath      = $rename->getSource()->getPathname();
            $renameContentId = $contentIdentifierMap[$renamePath] ?? null;

            if ($renameContentId !== null) {
                $subGroupNum = $contentIdToSubGroup[$renameContentId] ?? 0;
            } else {
                // Fallback: match by source filename stem (e.g. IMG_0001.mov -> IMG_0001).
                // This handles MOV companions that lack a content identifier in their metadata
                // but share the same source filename stem as their paired still image.
                $renameBasename = FileHelper::basenameWithoutExtension($rename->getSource());
                $subGroupNum    = $sourceBasenameToSubGroup[$renameBasename] ?? 0;
            }

            $fileBasename = $subGroupNum === 0
                ? $canonicalBasename
                : sprintf('%s-%03d', $canonicalBasename, $subGroupNum);

            $ext = strtolower($rename->getTarget()->getExtension());

            // First file per content ID is the companion (no duplicate suffix).
            $contentIdKey = $renameContentId ?? '__none_' . $renamePath;

            if (isset($excludedDuplicateCountByContentId[$contentIdKey])) {
                $dupIdx            = $excludedDuplicateCountByContentId[$contentIdKey];
                $newTargetFilename = sprintf(
                    '%s%s%03d.%s',
                    $fileBasename,
                    Constants::DUPLICATE_IDENTIFIER,
                    $dupIdx,
                    $ext,
                );

                ++$excludedDuplicateCountByContentId[$contentIdKey];
            } else {
                $excludedDuplicateCountByContentId[$contentIdKey] = 1;
                $newTargetFilename                                = $fileBasename . '.' . $ext;
            }

            $targetPathname = $targetPathnameResolver(
                $rename->getSource(),
                $newTargetFilename,
            );

            $rename->setTarget(new SplFileInfo($targetPathname));
            $newRenames[] = $rename;
        }

        // Replace the renames in the fileDuplicate with the newly ordered list.
        $fileDuplicate->setRenames(new RenameList($newRenames));

        // Update the group's canonical target to match the first sub-group's canonical.
        if ($canonicalRename instanceof Rename) {
            $fileDuplicate->setTarget($canonicalRename->getTarget());
        }

        return true;
    }

    /**
     * Releases all cached hash results to free memory.
     */
    #[Override]
    public function clearCache(): void
    {
        $this->hashCalculator->clearCache();
    }
}
