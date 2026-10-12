#!/usr/bin/env bash

# Check the immutable artifact against its actual vendor/lock, not host intent.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
docker compose run --rm -T --volume /media --entrypoint php runtime <<'PHP'
<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use Composer\InstalledVersions;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function count;
use function hash;
use function json_decode;
use function ksort;
use function preg_match;

use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const PHP_VERSION;

require '/app/.build/vendor/autoload.php';
$filesystem = new Filesystem();
$manifest = json_decode($filesystem->readFile('/app/.build/build-inputs.json'), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode($filesystem->readFile('/app/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
if ($manifest['schema'] !== 1 || $manifest['kind'] !== 'runtime'
    || $manifest['composer_lock_sha256'] !== hash('sha256', $filesystem->readFile('/app/composer.lock'))
    || $manifest['tools']['php'] !== PHP_VERSION) {
    throw new RuntimeException('Runtime manifest does not match the actual immutable inputs.');
}
$expected = [];
foreach ($lock['packages'] as $package) {
    $reference = $package['source']['reference'] ?? null;
    $expected[$package['name']] = [$package['version'], $reference];
    if (InstalledVersions::getPrettyVersion($package['name']) !== $package['version']
        || InstalledVersions::getReference($package['name']) !== $reference) {
        throw new RuntimeException('Actual immutable vendor metadata differs from its locked references.');
    }
}
$actual = [];
foreach ($manifest['packages'] as $package) {
    $actual[$package['name']] = [$package['version'], $package['reference']];
}
ksort($expected);
ksort($actual);
if ($actual !== $expected) {
    throw new RuntimeException('Production vendor references differ from the locked runtime packages.');
}
if (count($manifest['base_images']) !== 3 || preg_match('/^[a-f0-9]{64}$/D', $manifest['source_sha256']) !== 1) {
    throw new RuntimeException('Runtime source/base provenance is incomplete.');
}
echo 'Immutable runtime manifest matches every locked production package and tool input.' . PHP_EOL;
PHP
