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
 * Tracks deferred legacy Live Photo files for one normalized content identifier.
 *
 * During the first legacy grouping pass, videos with a content identifier may
 * appear before their still image or may never find a still at all. This small
 * mutable DTO stores the pending files, the eventually resolved duplicate group
 * identifier, and the fallback target used when no still is found.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class LegacyContentIdentifierCacheEntry
{
    /**
     * @var list<SplFileInfo>
     */
    private array $pendingFiles = [];

    private ?string $duplicateIdentifier = null;

    private ?SplFileInfo $target = null;

    /**
     * @return string|null The resolved duplicate group identifier once a still image anchored the pair.
     */
    public function getDuplicateIdentifier(): ?string
    {
        return $this->duplicateIdentifier;
    }

    /**
     * Records the duplicate group identifier and canonical target once a still
     * image anchored this content identifier to a concrete duplicate group.
     *
     * @param string      $duplicateIdentifier Resolved duplicate group identifier for this content identifier.
     * @param SplFileInfo $target              Canonical group target to reuse for queued companion files.
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
