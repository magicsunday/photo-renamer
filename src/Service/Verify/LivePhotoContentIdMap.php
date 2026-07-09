<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

/**
 * Aggregated verify-scan map of content identifiers grouped by directory.
 *
 * The verify command needs a second pass that can ask "for this directory and
 * content identifier, which still/video observations were seen?" This wrapper
 * keeps that cross-service contract explicit without exposing a nested shape
 * array across MetadataIssueScanner, VerifyScanResult, and
 * LivePhotoCompletenessAnalyzer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class LivePhotoContentIdMap
{
    /**
     * @var array<string, array<string, list<LivePhotoContentIdObservation>>>
     */
    private array $entries = [];

    /**
     * Adds one observed file under its directory and content identifier.
     *
     * @param string                        $directory   Absolute parent directory of the observed file
     * @param string                        $contentId   Normalized Apple content identifier
     * @param LivePhotoContentIdObservation $observation Observed file metadata needed for completeness analysis
     */
    public function add(string $directory, string $contentId, LivePhotoContentIdObservation $observation): void
    {
        $this->entries[$directory][$contentId][] = $observation;
    }

    /**
     * Returns whether no content-identifier observations were recorded.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Returns all directories that currently contain content-identifier observations.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Returns all content identifiers seen within one directory.
     *
     * @param string $directory Absolute directory key
     *
     * @return list<string>
     */
    public function contentIdsInDirectory(string $directory): array
    {
        return array_keys($this->entries[$directory] ?? []);
    }

    /**
     * Returns the recorded observations for one directory/content-id bucket.
     *
     * @param string $directory Absolute directory key
     * @param string $contentId Normalized Apple content identifier
     *
     * @return list<LivePhotoContentIdObservation>
     */
    public function observationsFor(string $directory, string $contentId): array
    {
        return $this->entries[$directory][$contentId] ?? [];
    }

    /**
     * Returns whether a directory/content-id bucket exists.
     *
     * @param string $directory Absolute directory key
     * @param string $contentId Normalized Apple content identifier
     */
    public function hasBucket(string $directory, string $contentId): bool
    {
        return isset($this->entries[$directory][$contentId]);
    }
}
