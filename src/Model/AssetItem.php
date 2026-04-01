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
 * Immutable value object — all mutations return new instances via with*() methods.
 * Trade-off: 6 with*() methods × 11 params = maintenance cost on field addition.
 * Accepted because: pipeline phases get explicit data flow (no hidden mutation),
 * and AssetGroup::replaceItem() makes updates visible at the call site.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class AssetItem
{
    /**
     * @param SplFileInfo            $file              The source file
     * @param ItemRole               $role              Role within the asset group
     * @param DuplicateRelation|null $duplicateRelation How this relates to the canonical (null for non-duplicates)
     * @param TemporalMetadata|null  $metadata          Cached temporal metadata
     * @param string|null            $contentIdentifier Normalized Live Photo content identifier
     * @param string|null            $proposedName      Full proposed target pathname
     * @param bool                   $renameRequired    Whether the file needs renaming
     * @param int                    $priorityScore     Canonical selection score
     * @param list<string>           $reasoning         Human-readable scoring breakdown
     * @param int|null               $sequenceNumber    Duplicate sequence number (1-based)
     * @param string|null            $clusterId         Subgroup/cluster ID from SubgroupClassifier
     * @param int|null               $clusterRank       Stable intra-cluster rank from SubgroupClassifier (0-based)
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
     * @param ItemRole               $role     New role to assign
     * @param DuplicateRelation|null $relation How this item relates to its canonical (null clears)
     *
     * @return self New instance with updated role
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
     * Returns a new instance with the given metadata and optional content identifier.
     *
     * @param TemporalMetadata|null $metadata          Temporal metadata to attach
     * @param string|null           $contentIdentifier Normalized Live Photo content identifier
     *
     * @return self New instance with updated metadata
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
     * Returns a new instance with the given proposed pathname.
     * Automatically computes renameRequired by comparing with the current file pathname.
     *
     * @param string $pathname Full proposed target pathname
     *
     * @return self New instance with updated proposed name and renameRequired flag
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
     *
     * @param int          $score     Canonical selection priority score
     * @param list<string> $reasoning Human-readable scoring breakdown entries
     *
     * @return self New instance with updated score and reasoning
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
     *
     * @param int $number 1-based duplicate sequence number
     *
     * @return self New instance with updated sequence number
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
     * @param string $clusterId Subgroup/cluster ID from SubgroupClassifier
     *
     * @return self New instance with updated cluster ID
     */
    public function withClusterId(string $clusterId): self
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
     * @param int $rank 0-based stable intra-cluster rank from SubgroupClassifier
     *
     * @return self New instance with updated cluster rank
     */
    public function withClusterRank(int $rank): self
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
     * Returns true when the proposed name is set and matches the current file pathname exactly.
     */
    public function matchesProposedNameExactly(): bool
    {
        return ($this->proposedName !== null) && ($this->proposedName === $this->file->getPathname());
    }

    /**
     * Returns true when the file basename (without extension) matches the date-based naming pattern.
     */
    public function matchesNamingPattern(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', $this->file->getBasename('.' . $this->file->getExtension())) === 1;
    }

    /**
     * Returns the lowercase file extension.
     */
    public function extension(): string
    {
        return strtolower($this->file->getExtension());
    }

    /**
     * Returns the directory depth (count of directory separators in the file path).
     */
    public function dirDepth(): int
    {
        return substr_count($this->file->getPathname(), DIRECTORY_SEPARATOR);
    }
}
