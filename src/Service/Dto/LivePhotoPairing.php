<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use SplFileInfo;

/**
 * Value object describing a matched Live Photo pair.
 */
final class LivePhotoPairing
{
    /**
     * Creates a pairing between a source and target file.
     *
     * @param SplFileInfo $sourceFile Original Live Photo asset discovered by the scanner.
     * @param SplFileInfo $targetFile Destination asset that will be renamed.
     * @param string $duplicateIdentifier Identifier used to detect duplicate pairings.
     * @param string $contentIdentifier Identifier linking assets that belong to the same Live Photo.
     */
    public function __construct(
        private readonly SplFileInfo $sourceFile,
        private readonly SplFileInfo $targetFile,
        private readonly string $duplicateIdentifier,
        private readonly string $contentIdentifier,
    ) {
    }

    /**
     * Returns the matched source file.
     *
     * @return SplFileInfo Source asset used as the Live Photo reference.
     */
    public function getSourceFile(): SplFileInfo
    {
        return $this->sourceFile;
    }

    /**
     * Returns the target file that should be renamed.
     *
     * @return SplFileInfo Target asset matched to the source file.
     */
    public function getTargetFile(): SplFileInfo
    {
        return $this->targetFile;
    }

    /**
     * Returns the duplicate detection identifier for the pairing.
     *
     * @return string Identifier ensuring the pairing is processed once.
     */
    public function getDuplicateIdentifier(): string
    {
        return $this->duplicateIdentifier;
    }

    /**
     * Returns the shared content identifier for the pairing.
     *
     * @return string Identifier linking all assets in the Live Photo group.
     */
    public function getContentIdentifier(): string
    {
        return $this->contentIdentifier;
    }
}
