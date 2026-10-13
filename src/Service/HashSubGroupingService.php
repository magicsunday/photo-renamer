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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\MergeDecision;
use MagicSunday\Renamer\Model\Rename;
use Override;
use SplFileInfo;

use function spl_object_id;
use function sprintf;
use function strtolower;

/**
 * Legacy naming adapter over the shared source-only content classifier.
 *
 * Retains subgroup suffixes, directory conflicts and idempotent companion names
 * for simple commands; exact/perceptual membership and evidence live only in the
 * shared kernel used directly by the EXIF pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class HashSubGroupingService implements HashSubGroupingServiceInterface
{
    /**
     * Keeps the shared content kernel behind the legacy naming boundary.
     *
     * @param ContentClassifierInterface $contentClassifier Shared content/evidence rules
     */
    public function __construct(private ContentClassifierInterface $contentClassifier)
    {
    }

    /**
     * Forwards the run preference to the shared kernel, preserving legacy setup.
     *
     * @param float|null $threshold Validated threshold or null to restore default
     *
     * @return void
     */
    #[Override]
    public function setMaxMergeRmse(?float $threshold): void
    {
        $this->contentClassifier->setMaxMergeRmse($threshold);
    }

    /**
     * Applies content-hash sub-grouping when a naming conflict exists.
     *
     * Returns the complete cluster map when independent content groups remain,
     * after updating legacy rename targets. Null leaves ordinary duplicate suffix
     * assignment to the caller when subgroup naming is unnecessary.
     *
     * @param FileDuplicate                        $fileDuplicate          the duplicate group to process
     * @param Rename|null                          $canonicalRename        the canonical rename entry
     * @param Rename|null                          $companionRename        the Live Photo companion rename entry
     * @param array<string, string>                $contentIdentifierMap   map from source pathname to content identifier
     * @param Closure(SplFileInfo, string): string $targetPathnameResolver resolves (sourceFileInfo, targetFilename) to absolute target path
     * @param array<string, TemporalMetadata|null> $temporalMetadataMap    map from source pathname to temporal metadata (for video duration)
     * @param Closure(MergeDecision): void|null    $onDecision             Optional streaming observer for typed decisions; no comparison history is retained
     *
     * @return array<string, string>|null Map from source pathname to cluster root hash key, or null when not needed
     */
    #[Override]
    public function apply(
        FileDuplicate $fileDuplicate,
        ?Rename $canonicalRename,
        ?Rename $companionRename,
        array $contentIdentifierMap,
        Closure $targetPathnameResolver,
        array $temporalMetadataMap = [],
        ?Closure $onDecision = null,
    ): ?array {
        $files         = [];
        $renamesByPath = [];

        foreach ($fileDuplicate->getRenames() as $rename) {
            $files[]                                            = $rename->getSource();
            $renamesByPath[$rename->getSource()->getPathname()] = $rename;
        }

        $classification = $this->contentClassifier->classify($files, $canonicalRename?->getSource(), $companionRename?->getSource(), $contentIdentifierMap, $temporalMetadataMap, $onDecision);

        if (!$classification->hasSubgroups()) {
            return null;
        }

        $nonCompanionRenames = [];

        foreach ($classification->classifiedFiles as $file) {
            $nonCompanionRenames[] = $renamesByPath[$file->getPathname()];
        }

        $hashGroups = [];

        foreach ($classification->groups as $hash => $groupFiles) {
            $hashGroups[$hash] = [];

            foreach ($groupFiles as $file) {
                $hashGroups[$hash][] = $renamesByPath[$file->getPathname()];
            }
        }

        $renameToHash = $classification->sourceHashes;

        /** @var array<int, true> $nonCompanionLookup */
        $nonCompanionLookup = [];

        foreach ($nonCompanionRenames as $nonCompanionRename) {
            $nonCompanionLookup[spl_object_id($nonCompanionRename)] = true;
        }

        // Companion identity describes the capture, not the still's pixels.
        // Different/uncertain image decisions are terminal: even identical MOV
        // bytes cannot turn a rejected still pair into a positive merge.

        // Multiple hashes: naming conflict. The canonical's sub-group keeps the
        // unsuffixed base name; other sub-groups get sequential numbers starting at 002.
        // Note: the SubSecond semantic duplicate heuristic has been moved to
        // DuplicateDetectionService where it has access to TemporalMetadata software tags.
        $canonicalBasename = FileHelper::basenameWithoutExtension($fileDuplicate->getTarget());

        // Assign sub-group numbers: canonical's hash gets 0 (no suffix), others get 2, 3, ...
        // Map each merged group key AND all original hashes within that group to the
        // same sub-group number. This ensures lookups via $renameToHash find the correct
        // sub-group even when a perceptual merge absorbed a file's hash into another root.

        /** @var list<Rename> $newRenames */
        $newRenames = [];

        /** @var array<string, int> $hashToSubGroup Map from hash to sub-group number (0 = canonical group) */
        $hashToSubGroup = [];

        $groupRanks = $classification->groupRanks();

        foreach ($hashGroups as $mergedKey => $groupRenames) {
            $groupNum = $groupRanks[$mergedKey];

            $hashToSubGroup[$mergedKey] = $groupNum;

            foreach ($groupRenames as $rename) {
                $origHash = $renameToHash[$rename->getSource()->getPathname()] ?? null;

                if (($origHash !== null) && ($origHash !== $mergedKey)) {
                    $hashToSubGroup[$origHash] = $groupNum;
                }
            }
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
                    && (!$isCanonicalGroup)
                    && (($dirFileCounts[$renameDir] ?? 0) <= 1);

                if ($isCrossDirNoConflict) {
                    $newTargetFilename = $canonicalBasename . '.' . $ext;
                    $targetPathname    = $targetPathnameResolver($rename->getSource(), $newTargetFilename);

                    $rename->setTarget(new SplFileInfo($targetPathname));
                    $newRenames[] = $rename;

                    continue;
                }

                // In the canonical group, the actual canonical rename gets no suffix.
                // In other groups, prefer the file whose source name already matches
                // the sub-group target (idempotent canonical preference). Falls back
                // to the first file if no name match exists.
                $isSubGroupCanonical = $isCanonicalGroup
                    ? (($canonicalRename instanceof Rename) && ($rename === $canonicalRename))
                    : ($rename === $this->findSubGroupCanonical($groupRenames, $subGroupBasename));

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
            $stillSubGroup = (($stillHash !== null) && isset($hashToSubGroup[$stillHash]))
                ? $hashToSubGroup[$stillHash]
                : 0;
            $stillContentId = $contentIdentifierMap[$stillPath] ?? null;

            if ($stillContentId !== null) {
                $contentIdToSubGroup[$stillContentId] = $stillSubGroup;
            }

            $stillBasename                            = FileHelper::basenameWithoutExtension($stillRename->getSource());
            $sourceBasenameToSubGroup[$stillBasename] = $stillSubGroup;
        }

        // Pre-identify the preferred companion per content ID (idempotent preference).
        // When multiple excluded files share a content ID, prefer the one whose source
        // basename already matches the expected target — just like idempotent canonical
        // selection in the non-companion (stills) path.
        /** @var array<string, Rename> $preferredExcludedCompanion */
        $preferredExcludedCompanion = [];

        foreach ($fileDuplicate->getRenames() as $rename) {
            if (isset($nonCompanionLookup[spl_object_id($rename)])) {
                continue;
            }

            $renamePath      = $rename->getSource()->getPathname();
            $renameContentId = $contentIdentifierMap[$renamePath] ?? null;
            $contentIdKey    = $renameContentId ?? '__none_' . $renamePath;

            if (isset($preferredExcludedCompanion[$contentIdKey])) {
                continue;
            }

            if ($renameContentId !== null) {
                $preSubGroup = $contentIdToSubGroup[$renameContentId] ?? 0;
            } else {
                $preBasename = FileHelper::basenameWithoutExtension($rename->getSource());
                $preSubGroup = $sourceBasenameToSubGroup[$preBasename] ?? 0;
            }

            $expectedBasename = $preSubGroup === 0
                ? $canonicalBasename
                : sprintf('%s-%03d', $canonicalBasename, $preSubGroup);

            if (FileHelper::basenameWithoutExtension($rename->getSource()) === $expectedBasename) {
                $preferredExcludedCompanion[$contentIdKey] = $rename;
            }
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

            // Assign companion slot: when a preferred companion exists for this content
            // ID (source basename matches expected target), only that rename gets the
            // unsuffixed companion name. Others become duplicates regardless of order.
            $contentIdKey = $renameContentId ?? '__none_' . $renamePath;
            $preferred    = $preferredExcludedCompanion[$contentIdKey] ?? null;

            $isCompanionSlot = ($preferred !== null)
                ? ($rename === $preferred)
                : !isset($excludedDuplicateCountByContentId[$contentIdKey]);

            if ($isCompanionSlot) {
                $excludedDuplicateCountByContentId[$contentIdKey] ??= 1;
                $newTargetFilename = $fileBasename . '.' . $ext;
            } else {
                $excludedDuplicateCountByContentId[$contentIdKey] ??= 1;

                $dupIdx            = $excludedDuplicateCountByContentId[$contentIdKey];
                $newTargetFilename = sprintf(
                    '%s%s%03d.%s',
                    $fileBasename,
                    Constants::DUPLICATE_IDENTIFIER,
                    $dupIdx,
                    $ext,
                );

                ++$excludedDuplicateCountByContentId[$contentIdKey];
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

        return $classification->clusterMap();
    }

    /**
     * Selects the sub-group canonical from a list of renames.
     * Prefers the file whose source basename already matches the sub-group target
     * (idempotent: already correctly named files stay canonical). Falls back to
     * the first file if no match exists.
     *
     * @param list<Rename> $renames
     */
    private function findSubGroupCanonical(array $renames, string $subGroupBasename): Rename
    {
        foreach ($renames as $rename) {
            $sourceBasename = FileHelper::basenameWithoutExtension($rename->getSource());

            if ($sourceBasename === $subGroupBasename) {
                return $rename;
            }
        }

        return $renames[0];
    }

    /**
     * Completes/releases shared analysis state before media mutation.
     *
     * @return void
     */
    #[Override]
    public function clearCache(): void
    {
        $this->contentClassifier->clearCache();
    }
}
