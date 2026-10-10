<?php

/**
 * Purges only the current UID's media caches, owned legacy files and DI cache.
 * Exact filenames keep a shared CACHE_DIR's unrelated data and users intact.
 */

declare(strict_types=1);

use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__) . '/.build/vendor/autoload.php';

$filesystem = new Filesystem();
$storage = new PrivateCacheStorage($filesystem);
$configuredBase = getenv('CACHE_DIR');
$base = is_string($configuredBase) && ($configuredBase !== '')
    ? $configuredBase
    : dirname(__DIR__) . '/.build/cache';
$privateDirectory = $base . '/private-' . posix_geteuid();

// Validate even dangling directory links, but do not create a cache during purge.
if (is_link($privateDirectory) || $filesystem->exists($privateDirectory)) {
    $privateDirectory = $storage->directory($base);

    foreach (['metadata-cache.json', 'perceptual-signal-cache.json'] as $filename) {
        $storage->remove($privateDirectory . '/' . $filename);
    }
}

// Old flat caches are not imported; remove owned copies during migration.
foreach (['metadata-cache.json', 'perceptual-signal-cache.json'] as $filename) {
    $storage->remove($base . '/' . $filename);
}

$storage->remove(dirname(__DIR__) . '/.build/cache/DependencyContainer.php');

echo "Current user's media caches, owned legacy files and DI cache cleared.\n";
