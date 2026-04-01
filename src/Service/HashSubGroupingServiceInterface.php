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
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use SplFileInfo;

/**
 * Contract for content-hash based sub-grouping of duplicate groups.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface HashSubGroupingServiceInterface
{
    /**
     * Applies content-hash sub-grouping when a naming conflict exists.
     *
     * Returns a cluster map (source pathname → cluster root hash key) when
     * sub-grouping was applied (multiple distinct content hashes found).
     * Returns null when sub-grouping is not needed (single hash group or
     * single non-companion file).
     *
     * @param FileDuplicate                        $fileDuplicate          the duplicate group to process
     * @param Rename|null                          $canonicalRename        the canonical rename entry
     * @param Rename|null                          $companionRename        the Live Photo companion rename entry
     * @param array<string, string>                $contentIdentifierMap   map from source pathname to content identifier
     * @param Closure(SplFileInfo, string): string $targetPathnameResolver resolves (sourceFileInfo, targetFilename) to absolute target path
     * @param array<string, TemporalMetadata|null> $temporalMetadataMap    map from source pathname to temporal metadata (for video duration)
     *
     * @return array<string, string>|null Map from source pathname to cluster root hash key, or null when not needed
     */
    public function apply(
        FileDuplicate $fileDuplicate,
        ?Rename $canonicalRename,
        ?Rename $companionRename,
        array $contentIdentifierMap,
        Closure $targetPathnameResolver,
        array $temporalMetadataMap = [],
    ): ?array;

    /**
     * Releases all cached hash results to free memory.
     */
    public function clearCache(): void;
}
