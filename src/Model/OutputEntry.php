<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

/**
 * Immutable value object representing a single entry in the rename output.
 * Replaces the untyped array<string, mixed> that was previously used.
 *
 * Three structural types exist (see {@see OutputEntryType}):
 * - Rename: a file operation with source/target paths and execution flags
 * - Skip: a file skipped during scanning, with a reason string
 * - Info: an informational notice attached to a nearby entry
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputEntry
{
    public function __construct(
        public string $sortKey,
        public OutputEntryType $type,
        public OutputEntryTag $tag,
        public string $sourcePath,
        public ?string $targetPath = null,
        public bool $isDuplicateTarget = false,
        public bool $shouldSkip = false,
        public bool $shouldPerformOperation = false,
        public ?string $reason = null,
        public ?string $warningReason = null,
    ) {
    }

    public function isRename(): bool
    {
        return $this->type === OutputEntryType::Rename;
    }

    public function isSkip(): bool
    {
        return $this->type === OutputEntryType::Skip;
    }

    public function isInfo(): bool
    {
        return $this->type === OutputEntryType::Info;
    }

    /**
     * Creates a rename entry.
     */
    public static function rename(
        string $sortKey,
        string $sourcePath,
        string $targetPath,
        OutputEntryTag $tag,
        bool $isDuplicateTarget = false,
        bool $shouldSkip = false,
        bool $shouldPerformOperation = false,
        ?string $warningReason = null,
    ): self {
        return new self(
            sortKey: $sortKey,
            type: OutputEntryType::Rename,
            tag: $tag,
            sourcePath: $sourcePath,
            targetPath: $targetPath,
            isDuplicateTarget: $isDuplicateTarget,
            shouldSkip: $shouldSkip,
            shouldPerformOperation: $shouldPerformOperation,
            warningReason: $warningReason,
        );
    }

    /**
     * Creates a skip entry (file skipped during scanning).
     */
    public static function skip(
        string $sortKey,
        string $sourcePath,
        string $reason,
        OutputEntryTag $tag,
    ): self {
        return new self(
            sortKey: $sortKey,
            type: OutputEntryType::Skip,
            tag: $tag,
            sourcePath: $sourcePath,
            reason: $reason,
        );
    }

    /**
     * Creates an info entry (informational notice).
     */
    public static function info(
        string $sortKey,
        string $sourcePath,
        string $reason,
        OutputEntryTag $tag = OutputEntryTag::Info,
    ): self {
        return new self(
            sortKey: $sortKey,
            type: OutputEntryType::Info,
            tag: $tag,
            sourcePath: $sourcePath,
            reason: $reason,
        );
    }
}
