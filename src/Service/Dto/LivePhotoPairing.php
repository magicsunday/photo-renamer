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
    public function __construct(
        private readonly SplFileInfo $sourceFile,
        private readonly SplFileInfo $targetFile,
        private readonly string $duplicateIdentifier,
        private readonly string $contentIdentifier,
    ) {
    }

    public function getSourceFile(): SplFileInfo
    {
        return $this->sourceFile;
    }

    public function getTargetFile(): SplFileInfo
    {
        return $this->targetFile;
    }

    public function getDuplicateIdentifier(): string
    {
        return $this->duplicateIdentifier;
    }

    public function getContentIdentifier(): string
    {
        return $this->contentIdentifier;
    }
}
