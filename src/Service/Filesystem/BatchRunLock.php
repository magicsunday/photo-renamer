<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;

use function clearstatcache;
use function dirname;
use function is_link;
use function posix_geteuid;

/**
 * Serializes cooperating media mutations with a kernel-managed Symfony lock.
 * One global resource covers overlapping trees and differently named media mounts
 * as long as processes share the same persistent state directory.
 */
final readonly class BatchRunLock
{
    /**
     * @param Filesystem $filesystem     Creates the persistent lock directory
     * @param string     $stateDirectory Shared state root, relative to the project or absolute
     */
    public function __construct(private Filesystem $filesystem, private string $stateDirectory)
    {
    }

    /**
     * Acquires without waiting so another running batch produces a useful error.
     * The returned lock must remain referenced until execution finishes; kernel
     * locks are released on process exit and their files must never be unlinked.
     *
     * @return SharedLockInterface Exclusive lock held by the caller
     */
    public function acquire(): SharedLockInterface
    {
        $directory = Path::makeAbsolute($this->stateDirectory, dirname(__DIR__, 3)) . '/locks';
        $this->filesystem->mkdir($directory, 0o700);
        clearstatcache(true, $directory);

        if (is_link($directory)) {
            throw new RuntimeException('Mutation lock directory must not be a symbolic link.');
        }

        $info = new SplFileInfo($directory);

        if (!$info->isDir() || ($info->getOwner() !== posix_geteuid())) {
            throw new RuntimeException('Mutation lock directory must be owned by the current process UID.');
        }

        $this->filesystem->chmod($directory, 0o700);

        $lock = new LockFactory(new FlockStore($directory))->createLock('renamer-media-mutation', ttl: null);

        if (!$lock->acquire()) {
            throw new RuntimeException('Another mutating renamer is running. Retry after it exits; do not delete lock files.');
        }

        return $lock;
    }
}
