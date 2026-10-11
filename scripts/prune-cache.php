<?php

/**
 * Removes cache entries for paths no longer visible as regular files. The
 * expected media tree must be mounted; this never deletes media or journals.
 * Reuse the mutation lock so maintenance does not overwrite a cooperating
 * mutating run's snapshot. Read-only analysis can still be a competing writer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use InvalidArgumentException;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function getenv;
use function is_string;
use function sprintf;

require dirname(__DIR__) . '/.build/vendor/autoload.php';

if ($argc !== 1) {
    throw new InvalidArgumentException('Usage: prune-cache.php');
}

$filesystem = new Filesystem();
$configuredBase = getenv('CACHE_DIR');
$base = is_string($configuredBase) && ($configuredBase !== '')
    ? $configuredBase
    : dirname(__DIR__) . '/.build/cache';
$lock = new BatchRunLock($filesystem, $base)->acquire();

try {
    $storage = new PrivateCacheStorage($filesystem);
    $directory = $storage->directory($base);
    $metadata = new MetadataCache($directory . '/metadata-cache.json', $storage);
    $signals = new PerceptualSignalCache($directory . '/perceptual-signal-cache.json', $storage);

    foreach ([$metadata, $signals] as $cache) {
        if ($cache->getDiagnostic() !== null) {
            echo $cache->getDiagnostic() . "\n";
        }
    }

    $metadataRemoved = $metadata->pruneMissing();
    $signalsRemoved = $signals->pruneMissing();
    $metadata->flush();
    $signals->flush();
    echo sprintf("Pruned %d metadata and %d perceptual entries. Media and journals unchanged.\n", $metadataRemoved, $signalsRemoved);
} finally {
    $lock->release();
}
