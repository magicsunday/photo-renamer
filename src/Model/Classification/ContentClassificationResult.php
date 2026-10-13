<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Classification;

use SplFileInfo;

use function count;
use function sprintf;

/**
 * Immutable content membership shared by the direct pipeline and legacy naming
 * adapter. It retains source views and hash identities, never mutable rename
 * targets or full pair history. Streamed MergeDecision values remain separate.
 */
final readonly class ContentClassificationResult
{
    /**
     * @param list<SplFileInfo>                $classifiedFiles   Source encounter order after companion-family exclusion
     * @param array<string, list<SplFileInfo>> $groups            Ordered content groups keyed by merged root hash
     * @param array<string, string>            $sourceHashes      Original classification hash for each included source
     * @param string|null                      $canonicalHash     Merged group containing the explicit preferred source, if any
     * @param array<string, string>            $companionClusters Excluded companion source to its still's merged root
     */
    public function __construct(
        public array $classifiedFiles,
        public array $groups = [],
        public array $sourceHashes = [],
        public ?string $canonicalHash = null,
        public array $companionClusters = [],
    ) {
    }

    /**
     * Reports whether independent groups require subgroup naming or assignment.
     *
     * @return bool True only when more than one merged content group remains
     */
    public function hasSubgroups(): bool
    {
        return count($this->groups) > 1;
    }

    /**
     * Preserves the existing stable ordering: preferred group zero, others from
     * two onward. The rank is an output hint, never content equivalence evidence.
     *
     * @return array<string, int> Merged root hash to stable subgroup rank
     */
    public function groupRanks(): array
    {
        $ranks = [];
        $next  = 2;

        foreach ($this->groups as $hash => $files) {
            $ranks[$hash] = $hash === $this->canonicalHash ? 0 : $next++;
        }

        return $ranks;
    }

    /**
     * Projects complete source membership into the existing ordered cluster-key
     * contract, including explicitly paired companions. Single groups need no
     * assignment; there is no target filename or naming callback involved.
     *
     * @return array<string, string> Source pathname to stable ordered cluster key
     */
    public function clusterMap(): array
    {
        if (!$this->hasSubgroups()) {
            return [];
        }

        $ranks = $this->groupRanks();
        $map   = [];

        foreach ($this->groups as $hash => $files) {
            $key = sprintf('%03d_%s', $ranks[$hash], $hash);

            foreach ($files as $file) {
                $map[$file->getPathname()] = $key;
            }
        }

        foreach ($this->companionClusters as $path => $hash) {
            $map[$path] = sprintf('%03d_%s', $ranks[$hash], $hash);
        }

        return $map;
    }
}
