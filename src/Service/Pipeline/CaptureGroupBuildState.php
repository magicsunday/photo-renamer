<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\ContentIdentifierCacheEntry;
use SplFileInfo;

/**
 * Mutable per-run state created fresh at the start of each CaptureGroupBuilder::build()
 * invocation and passed to private helper methods. Isolates build-time maps from instance
 * state so that CaptureGroupBuilder remains reentrant.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class CaptureGroupBuildState
{
    /**
     * Map from source pathname to normalized Live Photo content identifier.
     * Built during grouping, used for companion detection in downstream phases.
     *
     * @var array<string, string>
     */
    public array $contentIdentifierMap = [];

    /**
     * Temporal metadata captured during grouping for later conflict detection.
     *
     * @var array<string, TemporalMetadata>
     */
    public array $temporalMetadataMap = [];

    /**
     * All scanned files keyed by pathname for post-group heuristics.
     *
     * @var array<string, SplFileInfo>
     */
    public array $filesByPath = [];

    /**
     * Cache for content identifier resolution during grouping.
     * Maps normalized content identifiers to the shared mutable cache entry that
     * coordinates deferred companion files and eventual group resolution.
     *
     * @var array<string, ContentIdentifierCacheEntry>
     */
    public array $contentIdentifierCache = [];
}
