<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use SplFileInfo;

use function array_key_exists;
use function strtolower;
use function trim;

/**
 * Index of Live Photo targets keyed by the original basename of the asset.
 */
final class LivePhotoBasenameTargetMap
{
    /** @var array<string, LivePhotoContentIdentifierTarget> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $ambiguous = [];

    /**
     * Remembers the canonical target for the provided source basename.
     *
     * @param SplFileInfo $source              Asset belonging to the Live Photo group.
     * @param SplFileInfo $target              Canonical target generated for the group.
     * @param string      $duplicateIdentifier Identifier that represents the Live Photo group.
     */
    public function remember(SplFileInfo $source, SplFileInfo $target, string $duplicateIdentifier): void
    {
        $basenameKey = $this->normalizeBasename($source);

        if ($basenameKey === null) {
            return;
        }

        if (array_key_exists($basenameKey, $this->ambiguous)) {
            return;
        }

        if (array_key_exists($basenameKey, $this->targets)) {
            $existing = $this->targets[$basenameKey];

            if ($existing->getDuplicateIdentifier() === $duplicateIdentifier) {
                return;
            }

            unset($this->targets[$basenameKey]);
            $this->ambiguous[$basenameKey] = true;

            return;
        }

        $this->targets[$basenameKey] = new LivePhotoContentIdentifierTarget($target, $duplicateIdentifier);
    }

    /**
     * Resolves the stored target for the provided file using its basename.
     */
    public function match(SplFileInfo $file): ?LivePhotoContentIdentifierTarget
    {
        $basenameKey = $this->normalizeBasename($file);

        if ($basenameKey === null) {
            return null;
        }

        if (array_key_exists($basenameKey, $this->ambiguous)) {
            return null;
        }

        return $this->targets[$basenameKey] ?? null;
    }

    /**
     * Returns the normalized basename key used for the supplied file.
     */
    public function getBasenameKey(SplFileInfo $file): ?string
    {
        return $this->normalizeBasename($file);
    }

    private function normalizeBasename(SplFileInfo $file): ?string
    {
        $basename = $file->getBasename('.' . $file->getExtension());
        $normalized = strtolower(trim($basename));

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }
}
