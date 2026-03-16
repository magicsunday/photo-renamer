<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use SplFileInfo;

/**
 * Value object describing a matched Live Photo pair.
 */
final readonly class LivePhotoPairing
{
    /**
     * Creates a pairing between a source and target file.
     *
     * @param SplFileInfo $sourceFile          original Live Photo asset discovered by the scanner
     * @param SplFileInfo $targetFile          destination asset that will be renamed
     * @param string      $duplicateIdentifier identifier used to detect duplicate pairings
     * @param string      $contentIdentifier   identifier linking assets that belong to the same Live Photo
     */
    public function __construct(
        private SplFileInfo $sourceFile,
        private SplFileInfo $targetFile,
        private string $duplicateIdentifier,
        private string $contentIdentifier,
    ) {
    }

    /**
     * Returns the matched source file.
     *
     * @return SplFileInfo source asset used as the Live Photo reference
     */
    public function getSourceFile(): SplFileInfo
    {
        return $this->sourceFile;
    }

    /**
     * Returns the target file that should be renamed.
     *
     * @return SplFileInfo target asset matched to the source file
     */
    public function getTargetFile(): SplFileInfo
    {
        return $this->targetFile;
    }

    /**
     * Returns the duplicate detection identifier for the pairing.
     *
     * @return string identifier ensuring the pairing is processed once
     */
    public function getDuplicateIdentifier(): string
    {
        return $this->duplicateIdentifier;
    }

    /**
     * Returns the shared content identifier for the pairing.
     *
     * @return string identifier linking all assets in the Live Photo group
     */
    public function getContentIdentifier(): string
    {
        return $this->contentIdentifier;
    }
}
