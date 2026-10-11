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
 * Immutable identity of one regular file before analysis. Filesystem identity
 * catches replacement with identical bytes; SHA-256 also catches equal-size
 * in-place edits whose modification time was restored by another application.
 */
final readonly class SourceFileFingerprint
{
    /**
     * @param int    $device      Filesystem device containing the inode
     * @param int    $inode       Source inode observed before analysis
     * @param int    $size        Source byte count
     * @param int    $modifiedAt  Filesystem modification timestamp
     * @param int    $changedAt   Filesystem inode change timestamp
     * @param string $contentHash Fresh SHA-256 of the source bytes
     */
    public function __construct(
        public int $device,
        public int $inode,
        public int $size,
        public int $modifiedAt,
        public int $changedAt,
        public string $contentHash,
    ) {
    }

    /**
     * Compares identity and content, including device to distinguish equal inode
     * numbers on different mounted filesystems.
     *
     * @param SourceFileFingerprint $other Freshly observed file identity
     *
     * @return bool Whether all observed identity and content fields match
     */
    public function matches(self $other): bool
    {
        return ($this->device === $other->device)
            && ($this->inode === $other->inode)
            && ($this->size === $other->size)
            && ($this->modifiedAt === $other->modifiedAt)
            && ($this->changedAt === $other->changedAt)
            && ($this->contentHash === $other->contentHash);
    }
}
