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
use MagicSunday\Renamer\Model\Classification\ContentClassificationResult;
use MagicSunday\Renamer\Model\MergeDecision;
use SplFileInfo;

/**
 * Narrow content/evidence contract shared by the pipeline and legacy adapter.
 * Inputs are real sources, metadata and explicit pairing/order hints; naming
 * and mutation authority are intentionally outside this analysis boundary.
 */
interface ContentClassifierInterface
{
    /**
     * Classifies actual sources without creating or mutating rename targets.
     *
     * @param list<SplFileInfo>                    $files              Actual sources in stable encounter order
     * @param SplFileInfo|null                     $canonical          Preferred source used only for output ranking
     * @param SplFileInfo|null                     $companion          Actual paired media family to exclude from content comparison
     * @param array<string, string>                $contentIdentifiers Source to known content identifier
     * @param array<string, TemporalMetadata|null> $metadata           Source to temporal metadata
     * @param Closure(MergeDecision): void|null    $onDecision         Optional streaming evidence observer
     *
     * @return ContentClassificationResult Immutable content groups and source membership
     */
    public function classify(array $files, ?SplFileInfo $canonical, ?SplFileInfo $companion, array $contentIdentifiers = [], array $metadata = [], ?Closure $onDecision = null): ContentClassificationResult;

    /**
     * Selects a validated run threshold, or restores the default at cleanup.
     *
     * @param float|null $threshold Selected maximum RMSE, null restores the default
     *
     * @return void
     */
    public function setMaxMergeRmse(?float $threshold): void;

    /**
     * Completes partial signals and releases analysis caches before mutation.
     *
     * @return void
     */
    public function clearCache(): void;
}
