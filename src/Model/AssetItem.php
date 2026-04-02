<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use MagicSunday\Renamer\Metadata\TemporalMetadata;
use SplFileInfo;

use function preg_match;
use function strtolower;
use function substr_count;

use const DIRECTORY_SEPARATOR;

/**
 * Represents a single file within the renaming pipeline. This is an immutable value
 * object; all state changes return a new instance via with*() methods.
 *
 * It carries all information required to decide how the file should be handled:
 * - Metadata (capture date, camera info, Live Photo IDs)
 * - Assigned role (is it the 'original' or a duplicate/companion?)
 * - Proposed target path and why it was chosen (scoring/reasoning)
 *
 * Immutability ensures that data flow between pipeline phases remains explicit
 * and predictable, preventing side effects when sharing items across groups.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class AssetItem
{
    /**
     * @param SplFileInfo            $file              The source file on disk.
     * @param ItemRole               $role              Role within the asset group (e.g., Canonical, Duplicate).
     * @param DuplicateRelation|null $duplicateRelation Relationship to the canonical item (only set for duplicates).
     * @param TemporalMetadata|null  $metadata          Extracted capture date and associated metadata.
     * @param string|null            $contentIdentifier Normalized content identifier for Live Photos.
     * @param string|null            $proposedName      Proposed absolute target pathname for renaming.
     * @param bool                   $renameRequired    Whether the file actually needs to be moved/renamed.
     * @param int                    $priorityScore     Score for canonical selection (higher is better).
     * @param list<string>           $reasoning         List of reasons for the assigned score.
     * @param int|null               $sequenceNumber    Sequential number for duplicates (1-based).
     * @param string|null            $clusterId         Subgroup/cluster ID (e.g., burst ID).
     * @param int|null               $clusterRank       Stable rank within the cluster (0-based).
     */
    public function __construct(
        public SplFileInfo $file,
        public ItemRole $role = ItemRole::Canonical,
        public ?DuplicateRelation $duplicateRelation = null,
        public ?TemporalMetadata $metadata = null,
        public ?string $contentIdentifier = null,
        public ?string $proposedName = null,
        public bool $renameRequired = false,
        public int $priorityScore = 0,
        public array $reasoning = [],
        public ?int $sequenceNumber = null,
        public ?string $clusterId = null,
        public ?int $clusterRank = null,
    ) {
    }

    /**
     * Returns a new instance with the given role and optional duplicate relation.
     *
     * @param ItemRole               $role     New role (Canonical, Duplicate, Companion).
     * @param DuplicateRelation|null $relation Relationship to the canonical item.
     *
     * @return self New instance with updated role/relation.
     */
    public function withRole(ItemRole $role, ?DuplicateRelation $relation = null): self
    {
        return new self(
            $this->file,
            $role,
            $relation,
            $this->metadata,
            $this->contentIdentifier,
            $this->proposedName,
            $this->renameRequired,
            $this->priorityScore,
            $this->reasoning,
            $this->sequenceNumber,
            $this->clusterId,
            $this->clusterRank,
        );
    }

    /**
     * Returns a new instance with the given metadata and content identifier.
     *
     * @param TemporalMetadata|null $metadata          Capture date metadata (EXIF or filesystem).
     * @param string|null           $contentIdentifier Normalized Live Photo content identifier.
     *
     * @return self New instance with updated metadata.
     */
    public function withMetadata(?TemporalMetadata $metadata, ?string $contentIdentifier = null): self
    {
        return new self(
            $this->file,
            $this->role,
            $this->duplicateRelation,
            $metadata,
            $contentIdentifier,
            $this->proposedName,
            $this->renameRequired,
            $this->priorityScore,
            $this->reasoning,
            $this->sequenceNumber,
            $this->clusterId,
            $this->clusterRank,
        );
    }

    /**
     * Returns a new instance with the proposed target pathname.
     * Automatically computes renameRequired by comparing the target with the source path.
     *
     * @param string $pathname Absolute target pathname.
     *
     * @return self New instance with updated proposed name and rename flag.
     */
    public function withProposedName(string $pathname): self
    {
        return new self(
            $this->file,
            $this->role,
            $this->duplicateRelation,
            $this->metadata,
            $this->contentIdentifier,
            $pathname,
            $pathname !== $this->file->getPathname(),
            $this->priorityScore,
            $this->reasoning,
            $this->sequenceNumber,
            $this->clusterId,
            $this->clusterRank,
        );
    }

    /**
     * Returns a new instance with the given score and reasoning.
     * The score determines which file within a group is selected as canonical.
     *
     * @param int          $score     Quality score (higher is better).
     * @param list<string> $reasoning Reasons for this score.
     *
     * @return self New instance with updated score/reasoning.
     */
    public function withScore(int $score, array $reasoning): self
    {
        return new self(
            $this->file,
            $this->role,
            $this->duplicateRelation,
            $this->metadata,
            $this->contentIdentifier,
            $this->proposedName,
            $this->renameRequired,
            $score,
            $reasoning,
            $this->sequenceNumber,
            $this->clusterId,
            $this->clusterRank,
        );
    }

    /**
     * Returns a new instance with the given sequence number.
     * Used to differentiate duplicates in the target filename (e.g., "-001").
     *
     * @param int $number Sequential number (1-based).
     *
     * @return self New instance with updated sequence number.
     */
    public function withSequenceNumber(int $number): self
    {
        return new self(
            $this->file,
            $this->role,
            $this->duplicateRelation,
            $this->metadata,
            $this->contentIdentifier,
            $this->proposedName,
            $this->renameRequired,
            $this->priorityScore,
            $this->reasoning,
            $number,
            $this->clusterId,
            $this->clusterRank,
        );
    }

    /**
     * Returns a new instance with the given cluster ID.
     *
     * @param string|null $clusterId ID of the cluster (e.g., burst ID).
     *
     * @return self New instance with updated cluster ID.
     */
    public function withClusterId(?string $clusterId): self
    {
        return new self(
            $this->file,
            $this->role,
            $this->duplicateRelation,
            $this->metadata,
            $this->contentIdentifier,
            $this->proposedName,
            $this->renameRequired,
            $this->priorityScore,
            $this->reasoning,
            $this->sequenceNumber,
            $clusterId,
            $this->clusterRank,
        );
    }

    /**
     * Returns a new instance with the given cluster rank.
     *
     * @param int|null $rank Rank within the cluster (0-based).
     *
     * @return self New instance with updated cluster rank.
     */
    public function withClusterRank(?int $rank): self
    {
        return new self(
            $this->file,
            $this->role,
            $this->duplicateRelation,
            $this->metadata,
            $this->contentIdentifier,
            $this->proposedName,
            $this->renameRequired,
            $this->priorityScore,
            $this->reasoning,
            $this->sequenceNumber,
            $this->clusterId,
            $rank,
        );
    }

    /**
     * Returns true when the proposed target pathname matches the source pathname exactly.
     *
     * @return bool True if path and filename are identical.
     */
    public function matchesProposedNameExactly(): bool
    {
        return ($this->proposedName !== null) && ($this->proposedName === $this->file->getPathname());
    }

    /**
     * Returns true when the current filename already matches the target naming pattern.
     * Uses a regex-based heuristic.
     *
     * @return bool True if the pattern was recognized.
     */
    public function matchesNamingPattern(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', $this->file->getBasename('.' . $this->file->getExtension())) === 1;
    }

    /**
     * Returns the file extension in lowercase.
     *
     * @return string File extension (e.g., "jpg").
     */
    public function extension(): string
    {
        return strtolower($this->file->getExtension());
    }

    /**
     * Returns the directory depth of the file path.
     * Used to prefer shallower hierarchies when selecting the canonical item.
     *
     * @return int Number of directories in the path.
     */
    public function dirDepth(): int
    {
        return substr_count($this->file->getPathname(), DIRECTORY_SEPARATOR);
    }
}
