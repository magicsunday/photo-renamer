<?php

/**
 * Profiles the real metadata cache with a large synthetic versioned snapshot.
 * Only one tiny synthetic source remains present; historical paths exercise
 * explicit pruning. This isolates JSON/DTO load/flush cost from media parsing,
 * grouping, source hashing and actual mutation; it is not a collection SLA.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use Composer\InstalledVersions;
use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

use function filter_var;
use function getrusage;
use function hrtime;
use function json_decode;
use function json_encode;
use function memory_get_peak_usage;

use const FILTER_VALIDATE_INT;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_VERSION;

require '/app/.build/vendor/autoload.php';

$count = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT);
if (($argc !== 2) || ($count === false) || ($count < 1000) || ($count > 200000)) {
    throw new InvalidArgumentException('Use a synthetic entry count between 1000 and 200000.');
}

$filesystem = new Filesystem();
$workspace = '/tmp/synthetic-metadata-cache';
$filesystem->mkdir($workspace, 0o700);
$source = $workspace . '/retained.jpg';
$filesystem->dumpFile($source, 'synthetic bytes');
$file = new SplFileInfo($source);
$storage = new PrivateCacheStorage($filesystem);
$path = $storage->directory($workspace) . '/metadata-cache.json';
$metadata = new TemporalMetadata(
    new DateTimeImmutable('2024-01-15T10:30:00.123456+01:00'),
    'synthetic-content-id',
    true,
    true,
    8192,
    'Synthetic Make',
    'Synthetic Model',
    'Synthetic Software',
    0.25,
    -0.5,
    2.6,
    true,
    new DateTimeImmutable('2024-01-15T09:00:00.654321+00:00'),
);
$seed = new MetadataCache($path, $storage);
$seed->set($file, $metadata);
$seed->flush();
$payload = json_decode($filesystem->readFile($path), true, flags: JSON_THROW_ON_ERROR);
$row = json_encode($payload['entries'][$source], JSON_THROW_ON_ERROR);
$json = '{"_version":' . json_encode($payload['_version'], JSON_THROW_ON_ERROR)
    . ',"_parser":' . json_encode($payload['_parser'], JSON_THROW_ON_ERROR) . ',"entries":{' . "\n";

for ($index = 0; $index < $count; ++$index) {
    $pathname = $index === 0 ? $source : $workspace . '/missing-' . $index . '.jpg';
    $json .= ($index === 0 ? '' : ',') . json_encode($pathname, JSON_THROW_ON_ERROR) . ':' . $row . "\n";
}

$storage->write($path, $json . '}}');
unset($json, $payload, $seed);
$start = hrtime(true);
$cache = new MetadataCache($path, $storage);
$loadSeconds = (hrtime(true) - $start) / 1e9;
if (($cache->getDiagnostic() !== null) || ($cache->get($file) === null)) {
    throw new RuntimeException('Large fixture must be compatible and retain the existing source.');
}

$cache->set($file, $metadata);
$start = hrtime(true);
$cache->flush();
$flushSeconds = (hrtime(true) - $start) / 1e9;
$snapshotBytes = new SplFileInfo($path)->getSize();
$start = hrtime(true);
$removed = $cache->pruneMissing();
$pruneSeconds = (hrtime(true) - $start) / 1e9;
$cache->flush();
$prunedBytes = new SplFileInfo($path)->getSize();
unset($cache);
$fresh = new MetadataCache($path, $storage);
if (($removed !== ($count - 1)) || ($fresh->get($file) === null) || ($fresh->pruneMissing() !== 0)) {
    throw new RuntimeException('Pruning must remove only historical missing paths and retain the warm existing entry.');
}

$usage = getrusage();
echo json_encode([
    'entries' => $count,
    'php' => PHP_VERSION,
    'load_seconds' => $loadSeconds,
    'flush_seconds' => $flushSeconds,
    'prune_seconds' => $pruneSeconds,
    'snapshot_bytes' => $snapshotBytes,
    'pruned_bytes' => $prunedBytes,
    'removed_entries' => $removed,
    'php_peak_bytes' => memory_get_peak_usage(true),
    'linux_peak_rss_bytes' => $usage['ru_maxrss'] * 1024,
    'parser_reference' => InstalledVersions::getReference('magicsunday/imagemeta'),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
