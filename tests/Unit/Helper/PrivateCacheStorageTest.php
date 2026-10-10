<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function clearstatcache;
use function fileperms;
use function glob;
use function is_link;
use function link;
use function posix_geteuid;
use function umask;

/**
 * Exercises private inode creation, migration and unsafe cache boundaries on
 * real temporary paths, without using any personal media or location records.
 */
#[CoversClass(PrivateCacheStorage::class)]
final class PrivateCacheStorageTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A previously permissive per-user directory and file are corrected even
     * on a cache hit, while the configured shared parent retains its rights.
     */
    #[Test]
    public function correctsOwnedCacheWithoutChangingSharedParent(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $directory  = $workspace . '/private-' . posix_geteuid();
        $pathname   = $directory . '/metadata-cache.json';
        $filesystem->mkdir($directory);
        $filesystem->chmod([$workspace, $directory], 0o755);
        $filesystem->dumpFile($pathname, '{"latitude":12.345,"path":"/synthetic/photo.jpg"}');
        $filesystem->chmod($pathname, 0o644);

        $storage = new PrivateCacheStorage($filesystem);

        try {
            self::assertSame($directory, $storage->directory($workspace));
            self::assertStringContainsString('12.345', $storage->read($pathname) ?? '');
            clearstatcache(true);
            self::assertSame(0o700, fileperms($directory) & 0o777);
            self::assertSame(0o600, fileperms($pathname) & 0o777);
            self::assertSame(0o755, fileperms($workspace) & 0o777);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Before publishing JSON, the populated temporary inode is already 0600.
     * Replacing an existing 0644 destination must not inherit its old mode.
     */
    #[Test]
    public function replacementIsPrivateBeforeAndAfterPublishing(): void
    {
        $workspace      = $this->createTempWorkspace();
        $realFilesystem = new Filesystem();
        $pathname       = $workspace . '/metadata-cache.json';
        $realFilesystem->dumpFile($pathname, 'old');
        $realFilesystem->chmod($pathname, 0o644);

        $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['rename'])->getMock();
        $filesystem->expects(self::once())->method('rename')->willReturnCallback(
            static function (string $temporary, string $destination, bool $overwrite) use ($realFilesystem): void {
                clearstatcache(true);
                self::assertSame(0o600, fileperms($temporary) & 0o777);
                self::assertSame('new synthetic contents', $realFilesystem->readFile($temporary));
                $realFilesystem->rename($temporary, $destination, $overwrite);
            },
        );
        $previousUmask = umask(0o022);

        try {
            new PrivateCacheStorage($filesystem)->write($pathname, 'new synthetic contents');
            clearstatcache(true);
            self::assertSame(0o600, fileperms($pathname) & 0o777);
            self::assertSame([], glob($workspace . '/.renamer-cache-*'));
        } finally {
            umask($previousUmask);
            $realFilesystem->remove($workspace);
        }
    }

    /**
     * An atomic replacement failure preserves the previous cache and removes
     * the populated private temporary file instead of accumulating locations.
     */
    #[Test]
    public function failedReplacementRemovesTemporaryAndPreservesPreviousCache(): void
    {
        $workspace      = $this->createTempWorkspace();
        $realFilesystem = new Filesystem();
        $pathname       = $workspace . '/metadata-cache.json';
        $realFilesystem->dumpFile($pathname, 'previous');
        $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['rename'])->getMock();
        $filesystem->expects(self::once())->method('rename')->willThrowException(new IOException('Synthetic replacement failure.'));

        try {
            try {
                new PrivateCacheStorage($filesystem)->write($pathname, 'new');
                self::fail('A failed publication must be reported.');
            } catch (IOException) {
                self::assertSame('previous', $realFilesystem->readFile($pathname));
                self::assertSame([], glob($workspace . '/.renamer-cache-*'));
            }
        } finally {
            $realFilesystem->remove($workspace);
        }
    }

    /**
     * Links, including dangling links, must never redirect cache reads, writes
     * or purges into unrelated files owned by the same process.
     *
     * @param string $operation Storage operation being redirected
     * @param bool   $dangling  Whether the cache link points to an absent destination
     */
    #[Test]
    #[DataProvider('fileOperations')]
    public function rejectsLinkedCacheFiles(string $operation, bool $dangling): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $outside    = $workspace . '/unrelated.json';
        $pathname   = $workspace . '/metadata-cache.json';

        if (!$dangling) {
            $filesystem->dumpFile($outside, 'retained');
        }

        $filesystem->symlink($outside, $pathname);

        $storage = new PrivateCacheStorage($filesystem);

        try {
            try {
                if ($operation === 'read') {
                    $storage->read($pathname);
                } elseif ($operation === 'write') {
                    $storage->write($pathname, 'changed');
                } else {
                    $storage->remove($pathname);
                }

                self::fail('A symbolic cache file must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
                self::assertTrue(is_link($pathname));

                if ($dangling) {
                    self::assertFileDoesNotExist($outside);
                } else {
                    self::assertSame('retained', $filesystem->readFile($outside));
                }
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string, bool}> Operations on existing and dangling links
     */
    public static function fileOperations(): iterable
    {
        yield 'read' => ['read', false];
        yield 'replace' => ['write', false];
        yield 'purge' => ['remove', false];
        yield 'read dangling' => ['read', true];
        yield 'replace dangling' => ['write', true];
        yield 'purge dangling' => ['remove', true];
    }

    /**
     * A directory link cannot make permission correction chmod an unrelated
     * parent, even when both the link and destination are owned by this UID.
     */
    #[Test]
    public function rejectsLinkedPrivateDirectory(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $filesystem->mkdir($workspace . '/unrelated');
        $filesystem->chmod($workspace . '/unrelated', 0o755);
        $filesystem->symlink($workspace . '/unrelated', $workspace . '/private-' . posix_geteuid());

        try {
            try {
                new PrivateCacheStorage($filesystem)->directory($workspace);
                self::fail('Linked private directories must be rejected.');
            } catch (RuntimeException) {
                self::assertSame(0o755, fileperms($workspace . '/unrelated') & 0o777);
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * A hard-linked cache must not chmod an unrelated inode through its second
     * name; ownership alone does not establish a dedicated cache file.
     */
    #[Test]
    public function rejectsHardLinkedFilesBeforeChangingTheirMode(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $outside    = $workspace . '/unrelated.json';
        $filesystem->dumpFile($outside, 'retained');
        $filesystem->chmod($outside, 0o644);
        self::assertTrue(link($outside, $workspace . '/metadata-cache.json'));

        try {
            try {
                new PrivateCacheStorage($filesystem)->read($workspace . '/metadata-cache.json');
                self::fail('Hard-linked caches must be rejected.');
            } catch (RuntimeException) {
                self::assertSame(0o644, fileperms($outside) & 0o777);
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }
}
