<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use SplFileInfo;

/**
 * Extracts AssetItem candidates plus build-state metadata side effects.
 *
 * Capture-group assembly needs a fully populated AssetItem while also recording
 * temporal metadata and content identifiers in the mutable build state for later
 * Live Photo pairing and conflict analysis. Centralizing that extraction keeps
 * CaptureGroupBuilder focused on orchestration instead of low-level metadata wiring.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CaptureAssetCandidateExtractor
{
    /**
     * Creates an AssetItem with temporal metadata and content identifier populated.
     * Updates state maps (`temporalMetadataMap`, `contentIdentifierMap`) as side effects.
     *
     * @param SplFileInfo             $file     Source file to extract metadata for
     * @param RenameStrategyInterface $strategy Rename strategy that may expose metadata
     * @param CaptureGroupBuildState  $state    Mutable build-time state to update
     *
     * @return AssetItem Item with metadata and content identifier attached
     */
    public function extract(
        SplFileInfo $file,
        RenameStrategyInterface $strategy,
        CaptureGroupBuildState $state,
    ): AssetItem {
        $temporalMetadata = null;

        if ($strategy instanceof MetadataAwareRenameStrategyInterface) {
            try {
                $temporalMetadata = $strategy->getTemporalMetadata($file);
            } catch (TargetFilenameException) {
                $temporalMetadata = null;
            }

            if ($temporalMetadata instanceof TemporalMetadata) {
                $state->temporalMetadataMap[$file->getPathname()] = $temporalMetadata;
            }
        }

        $normalizedContentIdentifier = null;

        try {
            $normalizedContentIdentifier = $this->resolveNormalizedContentIdentifier($strategy, $file);
        } catch (TargetFilenameException) {
            $normalizedContentIdentifier = null;
        }

        if ($normalizedContentIdentifier !== null) {
            $state->contentIdentifierMap[$file->getPathname()] = $normalizedContentIdentifier;
        }

        $item = new AssetItem($file);

        return $item->withMetadata($temporalMetadata, $normalizedContentIdentifier);
    }

    /**
     * Resolves the normalized Live Photo content identifier for the given source file.
     *
     * @param RenameStrategyInterface $renameStrategy Strategy that may expose content identifiers
     * @param SplFileInfo             $sourceFileInfo Source file to query
     *
     * @return string|null Lowercased content identifier, or null
     *
     * @throws TargetFilenameException When reading metadata fails
     */
    private function resolveNormalizedContentIdentifier(
        RenameStrategyInterface $renameStrategy,
        SplFileInfo $sourceFileInfo,
    ): ?string {
        if (!$renameStrategy instanceof LivePhotoAwareRenameStrategyInterface) {
            return null;
        }

        return $renameStrategy->getLivePhotoContentIdentifier($sourceFileInfo);
    }
}
