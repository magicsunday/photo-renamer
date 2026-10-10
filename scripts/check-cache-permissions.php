<?php

/**
 * Verifies real Unix user isolation using only synthetic media and GPS values.
 * Run as root inside the disposable buildbox; children permanently drop UID/GID.
 * No host accounts, production caches or original media are modified.
 */

declare(strict_types=1);

use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/.build/vendor/autoload.php';

/**
 * Throws on a violated security contract rather than relying on disabled asserts.
 *
 * @param bool $condition Required invariant
 * @param string $message Synthetic failure diagnostic
 * @return void
 */
function requireCacheInvariant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$filesystem = new Filesystem();

if (($argv[1] ?? '') === 'child') {
    $uid = (int) ($argv[2] ?? '0');
    $base = $argv[3] ?? '';
    $action = $argv[4] ?? '';
    requireCacheInvariant(($uid > 0) && posix_setgid($uid) && posix_setuid($uid), 'Unable to drop child privileges.');
    requireCacheInvariant((posix_geteuid() === $uid) && (posix_getegid() === $uid), 'Wrong effective UID/GID.');
    umask(0o022);
    $storage = new PrivateCacheStorage($filesystem);

    if ($action === 'write') {
        $directory = $storage->directory($base);
        $media = $directory . '/synthetic.jpg';
        $filesystem->dumpFile($media, 'synthetic media');
        $cache = new MetadataCache($directory . '/metadata-cache.json', $storage);
        $cache->set(new SplFileInfo($media), new TemporalMetadata(
            new DateTimeImmutable('2024-01-01T12:00:00Z'),
            null,
            latitude: 12.345,
            longitude: 67.89,
        ));
        $cache->flush();
    } elseif ($action === 'read') {
        requireCacheInvariant($filesystem->readFile($base . '/public-control.json') === 'public control', 'Reader cannot reach shared parent.');
        requireCacheInvariant(!is_readable($base . '/private-1000/metadata-cache.json'), 'Other UID can read private locations.');

        try {
            $filesystem->readFile($base . '/private-1000/metadata-cache.json');
            throw new RuntimeException('Other UID read the private cache.');
        } catch (IOException) {
            // Expected real EACCES, not a mocked permission or mode assertion.
        }
    } elseif ($action === 'foreign-file') {
        try {
            $storage->read($base . '/private-1000/metadata-cache.json');
            throw new LogicException('Foreign-owned cache file was accepted.');
        } catch (RuntimeException) {
            // A foreign owner's readable file must not be chmodded or consumed.
        }
    } else {
        try {
            $storage->directory($base);
            throw new LogicException('Foreign-owned private child was accepted.');
        } catch (RuntimeException) {
            // Ownership must be rejected before chmod, even when root made it writable.
        }
    }

    exit(0);
}

requireCacheInvariant(posix_geteuid() === 0, 'Run this check as root inside the disposable Docker buildbox.');
$base = $filesystem->tempnam(sys_get_temp_dir(), 'renamer-private-cache-');
$filesystem->remove($base);
$filesystem->mkdir($base, 0o777);
$filesystem->chmod($base, 0o1777);
$filesystem->dumpFile($base . '/public-control.json', 'public control');
$filesystem->chmod($base . '/public-control.json', 0o644);

try {
    foreach ([[1000, 'write'], [1001, 'read'], [1001, 'write']] as [$uid, $action]) {
        new Process([PHP_BINARY, __FILE__, 'child', (string) $uid, $base, $action])->mustRun();
    }

    clearstatcache(true);

    foreach ([1000, 1001] as $uid) {
        $directory = $base . '/private-' . $uid;
        $cacheFile = $directory . '/metadata-cache.json';
        requireCacheInvariant((fileperms($directory) & 0o777) === 0o700, 'Private directory is not 0700.');
        requireCacheInvariant((fileperms($cacheFile) & 0o777) === 0o600, 'Private file is not 0600.');
        requireCacheInvariant((fileowner($cacheFile) === $uid) && (filegroup($cacheFile) === $uid), 'Cache UID/GID differ from process.');
    }

    requireCacheInvariant((fileperms($base) & 0o7777) === 0o1777, 'Shared parent was chmodded.');
    $filesystem->chown($base . '/private-1000', 0);
    $filesystem->chmod($base . '/private-1000', 0o777);
    new Process([PHP_BINARY, __FILE__, 'child', '1000', $base, 'foreign-directory'])->mustRun();
    clearstatcache(true);
    requireCacheInvariant((fileperms($base . '/private-1000') & 0o777) === 0o777, 'Foreign-owned directory was chmodded.');
    $filesystem->chown($base . '/private-1000', 1000);
    $filesystem->chmod($base . '/private-1000', 0o700);
    $filesystem->chown($base . '/private-1000/metadata-cache.json', 0);
    $filesystem->chmod($base . '/private-1000/metadata-cache.json', 0o644);
    new Process([PHP_BINARY, __FILE__, 'child', '1000', $base, 'foreign-file'])->mustRun();
    clearstatcache(true);
    requireCacheInvariant((fileperms($base . '/private-1000/metadata-cache.json') & 0o777) === 0o644, 'Foreign-owned file was chmodded.');

    echo "Cache isolation verified: UID/GID 1000 and 1001, 0600 files, 0700 directories, real denied read, unchanged shared parent, foreign directory/file rejection.\n";
} finally {
    $filesystem->remove($base);
}
