<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use SplFileInfo;

/**
 * Tracks deferred Live Photo files for one normalized content identifier.
 *
 * Both the legacy duplicate-detection path and the rename:exif pipeline need a
 * small mutable cache entry while scanning files in encounter order. Videos may
 * appear before their still image, or no still may be found at all, so this DTO
 * stores queued files, the resolved duplicate identifier once a still anchors
 * the group, and the fallback target used when resolution falls back to the
 * video's own date-based target.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class ContentIdentifierCacheEntry
{
    /**
     * @var list<SplFileInfo>
     */
    private array $pendingFiles = [];

    private ?string $duplicateIdentifier = null;

    private ?SplFileInfo $target = null;

    /**
     * @return string|null The resolved duplicate identifier once a still anchored the content identifier.
     */
    public function getDuplicateIdentifier(): ?string
    {
        return $this->duplicateIdentifier;
    }

    /**
     * Records the resolved duplicate identifier and canonical target once a
     * still image anchored this content identifier to a concrete group.
     *
     * @param string      $duplicateIdentifier Resolved duplicate identifier for this content identifier.
     * @param SplFileInfo $target              Canonical group target to reuse for deferred companion files.
     */
    public function rememberResolvedGroup(string $duplicateIdentifier, SplFileInfo $target): void
    {
        $this->duplicateIdentifier = $duplicateIdentifier;
        $this->target              = $target;
    }

    /**
     * Adds a deferred file that should be attached once a matching still is
     * found or later replayed into a fallback group.
     *
     * @param SplFileInfo $file Deferred file belonging to this content identifier.
     */
    public function addPendingFile(SplFileInfo $file): void
    {
        $this->pendingFiles[] = $file;
    }

    /**
     * @return list<SplFileInfo> Files currently queued for later resolution.
     */
    public function getPendingFiles(): array
    {
        return $this->pendingFiles;
    }

    /**
     * @return bool Whether deferred files are still waiting for a resolved group or fallback handling.
     */
    public function hasPendingFiles(): bool
    {
        return $this->pendingFiles !== [];
    }

    /**
     * Clears all queued deferred files after they were attached to a resolved group.
     */
    public function clearPendingFiles(): void
    {
        $this->pendingFiles = [];
    }

    /**
     * Remembers a fallback target the first time one is available.
     *
     * The first deferred video's own target is kept so unresolved content IDs
     * can later fall back to that date-based target if no still image appears.
     *
     * @param SplFileInfo $target Fallback target to store when no target was recorded yet.
     */
    public function rememberFallbackTarget(SplFileInfo $target): void
    {
        $this->target ??= $target;
    }

    /**
     * @return SplFileInfo|null Fallback or resolved target associated with this content identifier.
     */
    public function getTarget(): ?SplFileInfo
    {
        return $this->target;
    }
}
