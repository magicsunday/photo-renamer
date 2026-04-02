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
 * - Rename: a file operation with source/target paths and execution flags (can be a no-op or a skipped move)
 * - Skip: a file excluded during the initial scan phase (missing metadata, I/O error)
 * - Info: an informational notice attached to a nearby entry (e.g. "Duplicate of...")
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputEntry
{
    /**
     * @param string          $sortKey                Key used for stable sorting (usually source path)
     * @param OutputEntryType $type                   Structural type: Rename, Skip, or Info
     * @param OutputEntryTag  $tag                    Visual category for icons and colors
     * @param string          $sourcePath             Original source pathname
     * @param string|null     $targetPath             Computed target pathname (null for skips/info)
     * @param bool            $isDuplicateTarget      True if another file already targets this pathname
     * @param bool            $shouldSkip             True if the operation should be skipped in execution
     * @param bool            $shouldPerformOperation True if rename should actually be performed
     * @param string|null     $reason                 Brief reason for skip/info entries
     * @param string|null     $warningReason          Supplemental warning text for renames
     */
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

    /**
     * @return bool True if this entry represents a file rename operation.
     */
    public function isRename(): bool
    {
        return $this->type === OutputEntryType::Rename;
    }

    /**
     * @return bool True if this entry represents a skipped file.
     */
    public function isSkip(): bool
    {
        return $this->type === OutputEntryType::Skip;
    }

    /**
     * @return bool True if this entry is a pure informational message.
     */
    public function isInfo(): bool
    {
        return $this->type === OutputEntryType::Info;
    }

    /**
     * Creates a rename entry.
     *
     * @param string         $sortKey                Stable sort key (usually source path)
     * @param string         $sourcePath             Current filesystem pathname
     * @param string         $targetPath             Proposed target pathname
     * @param OutputEntryTag $tag                    Visual category
     * @param bool           $isDuplicateTarget      True if the target is already occupied
     * @param bool           $shouldSkip             True if execution should skip this
     * @param bool           $shouldPerformOperation True if disk move is required
     * @param string|null    $warningReason          Optional human-readable warning
     *
     * @return self The constructed Rename entry
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
     *
     * @param string         $sortKey    Stable sort key
     * @param string         $sourcePath Current filesystem pathname
     * @param string         $reason     Reason why the file was skipped
     * @param OutputEntryTag $tag        Visual category
     *
     * @return self The constructed Skip entry
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
     *
     * @param string         $sortKey    Stable sort key
     * @param string         $sourcePath Current filesystem pathname (anchor)
     * @param string         $reason     Information text
     * @param OutputEntryTag $tag        Visual category
     *
     * @return self The constructed Info entry
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
