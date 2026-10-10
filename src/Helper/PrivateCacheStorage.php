<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function clearstatcache;
use function dirname;
use function is_link;
use function posix_geteuid;
use function realpath;
use function sprintf;
use function stat;

/**
 * Applies the same private storage contract to both media JSON caches.
 * Directory permissions are corrected only on the dedicated per-UID child;
 * arbitrary configured parents remain untouched. Atomic replacement starts with
 * a private temporary inode so a permissive umask never exposes cache contents.
 */
final readonly class PrivateCacheStorage
{
    /**
     * @param Filesystem $filesystem Symfony boundary for reads, writes and permissions
     */
    public function __construct(private Filesystem $filesystem)
    {
    }

    /**
     * Prepares the current user's private child under a configurable base.
     * Existing children must be owned by the actual process UID before chmod.
     *
     * @param string $baseDirectory Configured cache base, possibly shared with other users
     *
     * @return string Dedicated media-cache directory with owner-only access
     */
    public function directory(string $baseDirectory): string
    {
        $directory = $baseDirectory . '/private-' . posix_geteuid();
        $this->filesystem->mkdir($directory, 0o700);
        clearstatcache(true);

        if (is_link($directory)) {
            throw new RuntimeException('Private cache directory must not be a symbolic link.');
        }

        $info = new SplFileInfo($directory);

        if (!$info->isDir() || ($info->getOwner() !== posix_geteuid())) {
            throw new RuntimeException('Private cache directory must be owned by the current process UID.');
        }

        $this->filesystem->chmod($directory, 0o700);

        return $directory;
    }

    /**
     * Loads only an owned regular file and corrects legacy permissive file rights
     * even on an otherwise read-only cache hit. Ordinary read failures retain
     * cold-cache behavior; inability to establish privacy is an explicit error.
     *
     * @param string $pathname Cache filename selected by the caller
     *
     * @return string|null Cache bytes, or null for a missing/unreadable file
     */
    public function read(string $pathname): ?string
    {
        if (!$this->isOwnedFile($pathname)) {
            return null;
        }

        $this->filesystem->chmod($pathname, 0o600);

        try {
            return $this->filesystem->readFile($pathname);
        } catch (IOException) {
            return null;
        }
    }

    /**
     * Replaces a cache atomically with a 0600 inode, including on its first write.
     * Populates an empty private temporary file through Symfony Filesystem,
     * then publishes that inode without inheriting the old destination mode.
     *
     * @param string $pathname Destination cache filename
     * @param string $contents Serialized cache contents
     *
     * @return void
     */
    public function write(string $pathname, string $contents): void
    {
        $this->isOwnedFile($pathname);
        $this->filesystem->mkdir(dirname($pathname), 0o700);
        $temporary = $this->filesystem->tempnam(dirname($pathname), '.renamer-cache-');

        try {
            if (realpath(dirname($temporary)) !== realpath(dirname($pathname))) {
                throw new RuntimeException('Private cache temporary file must remain in the destination directory.');
            }

            $this->filesystem->chmod($temporary, 0o600);
            $this->filesystem->appendToFile($temporary, $contents);
            $this->isOwnedFile($pathname);
            $this->filesystem->rename($temporary, $pathname, true);
        } finally {
            $this->filesystem->remove($temporary);
        }
    }

    /**
     * Deletes only an owned regular cache file; links and other users' files are
     * rejected rather than followed or silently removed from a shared base.
     *
     * @param string $pathname Exact cache filename to purge
     *
     * @return void
     */
    public function remove(string $pathname): void
    {
        if ($this->isOwnedFile($pathname)) {
            $this->filesystem->remove($pathname);
        }
    }

    /**
     * Checks the file boundary before changing permissions, reading or replacing.
     * This is local hardening, not an atomic guarantee against hostile directory
     * writers; configured cache parents must remain under trusted control.
     *
     * @param string $pathname Exact cache filename being accessed
     *
     * @return bool Whether an owned regular file exists at the pathname
     */
    private function isOwnedFile(string $pathname): bool
    {
        clearstatcache(true, $pathname);

        if (is_link($pathname)) {
            throw new RuntimeException(sprintf('Cache file must not be a symbolic link: "%s".', $pathname));
        }

        if (!$this->filesystem->exists($pathname)) {
            return false;
        }

        $info   = new SplFileInfo($pathname);
        $status = stat($pathname);

        if (!$info->isFile() || ($info->getOwner() !== posix_geteuid()) || ($status === false) || ($status['nlink'] !== 1)) {
            throw new RuntimeException(sprintf('Cache file must be regular, have one hard link and be owned by the current process UID: "%s".', $pathname));
        }

        return true;
    }
}
