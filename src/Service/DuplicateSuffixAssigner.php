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
use RuntimeException;
use SplFileInfo;

use function sprintf;

/**
 * Assigns unique duplicate suffix targets inside the legacy rename pipeline.
 *
 * The legacy duplicate flow needs a small but tricky set of idempotency rules:
 * canonicals keep the clean basename when possible, duplicates receive
 * `-duplicate-NNN`, and re-runs must preserve already-correct suffixed names.
 * This worker isolates that suffix policy from the surrounding group traversal.
 *
 * The current extraction intentionally receives occupancy and target-generation
 * callbacks from the caller. That keeps the worker focused on suffix policy
 * while `DuplicateDetectionService` still owns disk-index lookups and relative
 * target path construction.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class DuplicateSuffixAssigner
{
    /**
     * Resolves the target for the canonical file in a duplicate group.
     *
     * Canonical files keep the unsuffixed base name when it is free. When a
     * foreign file already occupies that path, the canonical is demoted to the
     * first available `-duplicate-NNN` target.
     *
     * @param SplFileInfo                                                  $source                   Source file currently being processed
     * @param SplFileInfo                                                  $target                   Initial target file information
     * @param int                                                          $duplicateCount           Counter used to create unique duplicate suffixes (passed by reference)
     * @param array<string, true>                                          $groupSourcePaths         Source paths of all files in the current group
     * @param Closure(SplFileInfo, SplFileInfo, array<string, true>): bool $isTargetOccupied         Closure that decides whether a candidate target is occupied
     * @param Closure(SplFileInfo, SplFileInfo, string, int): SplFileInfo  $createDuplicateCandidate Closure that builds the next `-duplicate-NNN` candidate
     *
     * @return SplFileInfo File information pointing to the deduplicated target
     */
    public function resolveCanonicalTarget(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        array $groupSourcePaths,
        Closure $isTargetOccupied,
        Closure $createDuplicateCandidate,
    ): SplFileInfo {
        if ($target->getPathname() === $source->getPathname()) {
            return $target;
        }

        if (!$isTargetOccupied($target, $source, $groupSourcePaths)) {
            return $target;
        }

        return $this->getNewUniqueDuplicateTargetFileInfo(
            $source,
            $target,
            FileHelper::basenameWithoutExtension($target),
            $duplicateCount,
            false,
            $groupSourcePaths,
            $isTargetOccupied,
            $createDuplicateCandidate,
        );
    }

    /**
     * Resolves the target file information for a duplicate, ensuring uniqueness.
     *
     * @param SplFileInfo                                                  $source                          Source file currently being processed
     * @param SplFileInfo                                                  $target                          Initial target file information
     * @param int                                                          $duplicateCount                  Counter used to create unique duplicate suffixes (passed by reference)
     * @param bool                                                         $isFirst                         Whether the file is the first item within the duplicate group
     * @param bool                                                         $hasAdditionalRenames            Whether the group has more than one non-canonical rename
     * @param bool                                                         $requiresCanonicalDisambiguation Whether the file shares the canonical target path
     * @param array<string, true>                                          $groupSourcePaths                Source paths of all files in the current group
     * @param Closure(SplFileInfo, SplFileInfo, array<string, true>): bool $isTargetOccupied                Closure that decides whether a candidate target is occupied
     * @param Closure(SplFileInfo, SplFileInfo, string, int): SplFileInfo  $createDuplicateCandidate        Closure that builds the next `-duplicate-NNN` candidate
     *
     * @return SplFileInfo File information pointing to the deduplicated target
     */
    public function createDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        bool $isFirst,
        bool $hasAdditionalRenames,
        bool $requiresCanonicalDisambiguation,
        array $groupSourcePaths,
        Closure $isTargetOccupied,
        Closure $createDuplicateCandidate,
    ): SplFileInfo {
        if (($target->getPathname() === $source->getPathname()) && !$requiresCanonicalDisambiguation) {
            return $target;
        }

        $targetOccupied = $isTargetOccupied($target, $source, $groupSourcePaths);
        $needsSuffix    = $targetOccupied || !$isFirst || $hasAdditionalRenames || $requiresCanonicalDisambiguation;

        if (!$needsSuffix) {
            return $target;
        }

        $duplicateBasename = FileHelper::basenameWithoutExtension($target);

        $forceSuffix = $targetOccupied
            ? $requiresCanonicalDisambiguation
            : ($hasAdditionalRenames || $requiresCanonicalDisambiguation);

        return $this->getNewUniqueDuplicateTargetFileInfo(
            $source,
            $target,
            $duplicateBasename,
            $duplicateCount,
            $forceSuffix,
            $groupSourcePaths,
            $isTargetOccupied,
            $createDuplicateCandidate,
        );
    }

    /**
     * Generates a target whose path does not collide with another file on disk.
     *
     * The counter is advanced until a free candidate is found. If a generated
     * suffixed path already equals the source path, the file is considered
     * idempotently correct and that source pathname is returned unchanged.
     *
     * @param SplFileInfo                                                  $source                   Source file currently being processed
     * @param SplFileInfo                                                  $target                   Initial target file information
     * @param string                                                       $targetBasename           Base filename used for duplicate naming
     * @param int                                                          $duplicateCount           Counter used to create unique duplicate suffixes (passed by reference)
     * @param bool                                                         $forceDuplicateSuffix     When true, always apply a suffix even if the target is free
     * @param array<string, true>                                          $groupSourcePaths         Source paths of all files in the current group
     * @param Closure(SplFileInfo, SplFileInfo, array<string, true>): bool $isTargetOccupied         Closure that decides whether a candidate target is occupied
     * @param Closure(SplFileInfo, SplFileInfo, string, int): SplFileInfo  $createDuplicateCandidate Closure that builds the next `-duplicate-NNN` candidate
     *
     * @return SplFileInfo File info pointing to a non-occupied target path
     */
    public function getNewUniqueDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
        bool $forceDuplicateSuffix,
        array $groupSourcePaths,
        Closure $isTargetOccupied,
        Closure $createDuplicateCandidate,
    ): SplFileInfo {
        $duplicateFileInfo = $target;

        if ($forceDuplicateSuffix) {
            $duplicateFileInfo = $createDuplicateCandidate(
                $source,
                $target,
                $targetBasename,
                $duplicateCount,
            );

            ++$duplicateCount;

            if ($duplicateFileInfo->getPathname() === $source->getPathname()) {
                return $duplicateFileInfo;
            }
        }

        while ($isTargetOccupied($duplicateFileInfo, $source, $groupSourcePaths)) {
            if ($duplicateCount > Constants::MAX_DUPLICATE_SUFFIX) {
                throw new RuntimeException(
                    sprintf('Exceeded %d duplicate suffix attempts', Constants::MAX_DUPLICATE_SUFFIX)
                );
            }

            $duplicateFileInfo = $createDuplicateCandidate(
                $source,
                $target,
                $targetBasename,
                $duplicateCount,
            );

            ++$duplicateCount;

            if ($duplicateFileInfo->getPathname() === $source->getPathname()) {
                break;
            }
        }

        return $duplicateFileInfo;
    }
}
