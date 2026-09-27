<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src/',
        __DIR__ . '/tests/',
    ]);

    // Keep the Rector caches inside the build directory rather than the repository root.
    $rectorCacheDirectory          = __DIR__ . '/.build/cache/.rector.cache';
    $rectorContainerCacheDirectory = __DIR__ . '/.build/cache/.rector.container.cache';

    foreach ([$rectorCacheDirectory, $rectorContainerCacheDirectory] as $cacheDirectory) {
        if (
            !is_dir($cacheDirectory)
            && !mkdir($cacheDirectory, 0o775, true)
            && !is_dir($cacheDirectory)
        ) {
            throw new RuntimeException(sprintf('Directory "%s" was not created.', $cacheDirectory));
        }
    }

    $rectorConfig->cacheDirectory($rectorCacheDirectory);
    $rectorConfig->containerCacheDirectory($rectorContainerCacheDirectory);
    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');

    // The shared rule sets and skips; 80500 is this package's PHP floor.
    (require __DIR__ . '/.build/vendor/magicsunday/coding-standard/rector/base.php')($rectorConfig, 80500);
};
