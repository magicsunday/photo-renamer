<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use function basename;
use function sprintf;

/**
 * Immutable classification verdict with the evidence actually used to decide.
 * It travels through an optional streaming callback, rather than accumulating
 * every comparison in memory. Exact means a matching classification hash;
 * permanent deletion still needs the independent fresh SHA-256 guard.
 */
final readonly class MergeDecision
{
    /**
     * @param MergeDecisionKind   $kind             Evidence category and final verdict
     * @param MergeDecisionReason $reason           Stable explanation for acceptance or rejection
     * @param string              $leftPath         First source, or the file whose hashing failed
     * @param string|null         $rightPath        Second source when pair evidence exists
     * @param string|null         $contentHash      Matching classification hash for exact decisions
     * @param int|null            $similarityScore  Stage A score when perceptual analysis ran
     * @param int|null            $dhashDistance    Measured gradient-hash distance, when available
     * @param float|null          $rmse             Measured Stage B pixel difference, when available
     * @param float|null          $chromaDifference Measured Stage B color difference, when available
     * @param float|null          $mergeThreshold   Effective safety/operator threshold for this pair
     */
    public function __construct(
        public MergeDecisionKind $kind,
        public MergeDecisionReason $reason,
        public string $leftPath,
        public ?string $rightPath,
        public ?string $contentHash = null,
        public ?int $similarityScore = null,
        public ?int $dhashDistance = null,
        public ?float $rmse = null,
        public ?float $chromaDifference = null,
        public ?float $mergeThreshold = null,
    ) {
    }

    /**
     * Allows grouping only for positive evidence, never for an uncertain result.
     *
     * @return bool Whether the classifier may join the pair
     */
    public function permitsMerge(): bool
    {
        return ($this->kind === MergeDecisionKind::Exact) || ($this->kind === MergeDecisionKind::Perceptual);
    }

    /**
     * Explains the verdict using only available evidence. Basenames keep summary
     * entries compact; the typed decision retains both full source paths.
     *
     * @return string Verdict, reason, example files and measured evidence
     */
    public function describe(): string
    {
        $files = basename($this->leftPath);

        if ($this->rightPath !== null) {
            $files .= ' vs ' . basename($this->rightPath);
        }

        $description = sprintf('%s: %s [%s]', $this->kind->value, $this->reason->value, $files);

        if ($this->contentHash !== null) {
            $description .= ' hash=' . $this->contentHash;
        }

        if ($this->similarityScore !== null) {
            $description .= sprintf(' score=%d', $this->similarityScore);
        }

        if ($this->dhashDistance !== null) {
            $description .= sprintf(' dHash=%d', $this->dhashDistance);
        }

        if ($this->rmse !== null) {
            $description .= sprintf(' rmse=%.4f', $this->rmse);
        }

        if ($this->chromaDifference !== null) {
            $description .= sprintf(' chroma=%.4f', $this->chromaDifference);
        }

        if ($this->mergeThreshold !== null) {
            $description .= sprintf(' threshold=%.4f', $this->mergeThreshold);
        }

        return $description;
    }
}
