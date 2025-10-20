<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy;

use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use Override;
use SplFileInfo;

/**
 * Strategy that prefers the Apple Live Photo content identifier to group duplicates.
 */
class LivePhotoContentIdentifierStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * @param ExifDateFilenameStrategy $renameStrategy Strategy providing access to Live Photo metadata helpers.
     */
    public function __construct(
        private readonly ExifDateFilenameStrategy $renameStrategy,
    ) {
    }

    /**
     * Generates an identifier based on the Live Photo content identifier or falls back to the target filename.
     *
     * @param SplFileInfo $sourceFileInfo Source file inspected for Live Photo metadata.
     * @param SplFileInfo $targetFileInfo Target file used as fallback identifier.
     *
     * @return string|false
     */
    #[Override]
    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
    {
        $contentIdentifier = $this->renameStrategy->getLivePhotoContentIdentifier($sourceFileInfo);

        if (is_string($contentIdentifier) && $contentIdentifier !== '') {
            return 'live-photo:' . $contentIdentifier;
        }

        return $targetFileInfo->getFilename();
    }
}
