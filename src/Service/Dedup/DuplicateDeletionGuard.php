<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dedup;

use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use RuntimeException;
use SplFileInfo;

use function clearstatcache;

/**
 * Requires fresh byte identity before permanent duplicate removal. Filename
 * matching and perceptual similarity are deliberately insufficient evidence.
 * State checks reject changes observed while hashing; they do not provide an
 * atomic filesystem transaction against concurrent external writers.
 */
final readonly class DuplicateDeletionGuard
{
    /**
     * @param SafeHashCalculatorInterface $hashCalculator Streaming content hashing with explicit cache invalidation
     */
    public function __construct(private SafeHashCalculatorInterface $hashCalculator)
    {
    }

    /**
     * Verifies two regular files using fresh SHA-256 hashes and before/after
     * state checks. Call after confirmation, immediately before removal.
     * Missing, unreadable, changed or symlinked files fail closed.
     *
     * @param SplFileInfo $duplicate File proposed for removal
     * @param SplFileInfo $original  Retained file whose bytes must match
     *
     * @return bool Whether current evidence permits permanent removal
     */
    public function permitsDeletion(SplFileInfo $duplicate, SplFileInfo $original): bool
    {
        try {
            $duplicateState = $this->snapshot($duplicate);
            $originalState  = $this->snapshot($original);

            if (($duplicateState === null) || ($originalState === null) || ($duplicate->getSize() !== $original->getSize())) {
                return false;
            }

            $this->hashCalculator->clearCache();
            $duplicateHash = $this->hashCalculator->hashFile($duplicate, 'sha256');
            $originalHash  = $this->hashCalculator->hashFile($original, 'sha256');

            return ($duplicateHash === $originalHash)
                && ($duplicateState === $this->snapshot($duplicate))
                && ($originalState === $this->snapshot($original));
        } catch (RuntimeException) {
            return false;
        } finally {
            $this->hashCalculator->clearCache();
        }
    }

    /**
     * Captures fresh file identity and modification indicators without retaining
     * access time, which can change during a successful read.
     *
     * @param SplFileInfo $file File whose current state must remain stable
     *
     * @return list<int>|null Inode, size, modification time and change time, or null for unsupported paths
     *
     * @throws RuntimeException When a file disappears while reading its state
     */
    private function snapshot(SplFileInfo $file): ?array
    {
        clearstatcache(true, $file->getPathname());

        if ($file->isLink() || !$file->isFile()) {
            return null;
        }

        return [$file->getInode(), $file->getSize(), $file->getMTime(), $file->getCTime()];
    }
}
