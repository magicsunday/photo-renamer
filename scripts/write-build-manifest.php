<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use Composer\InstalledVersions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function count;
use function dirname;
use function explode;
use function get_loaded_extensions;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function json_encode;
use function ksort;
use function preg_match;
use function preg_match_all;
use function sort;
use function strlen;
use function substr;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use const PHP_VERSION;

require dirname(__DIR__) . '/.build/vendor/autoload.php';

/**
 * Hashes only public source inputs, independently of checkout location/mtime.
 * Reject links instead of following them into credentials or foreign data.
 *
 * @param Filesystem $filesystem Filesystem boundary for public file bytes
 * @param string     $root       Input checkout/packaging root
 *
 * @return string Stable SHA-256 of ordered relative names and content digests
 */
function publicSourceDigest(Filesystem $filesystem, string $root): string
{
    $files = [];

    foreach (['src', 'config', 'scripts'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || $file->isLink()) {
                throw new RuntimeException('Public build inputs must be regular, non-symlinked files.');
            }

            if ($file->isFile()) {
                $relative         = substr($file->getPathname(), strlen($root) + 1);
                $files[$relative] = $file->getPathname();
            }
        }
    }

    foreach (['composer.json', 'composer.lock', 'version', 'Dockerfile'] as $file) {
        $files[$file] = $root . '/' . $file;
    }

    ksort($files);
    $context = hash_init('sha256');

    foreach ($files as $name => $path) {
        if (new SplFileInfo($path)->isLink()) {
            throw new RuntimeException('Public build inputs must not be symlinked.');
        }

        hash_update($context, $name . "\0" . hash('sha256', $filesystem->readFile($path)) . "\n");
    }

    return hash_final($context);
}

$root       = dirname(__DIR__);
$filesystem = new Filesystem();
$output     = $argv[1] ?? $root . '/.build/build-inputs.json';
$revision   = $argv[2] ?? 'workspace';
$dirtyInput = $argv[3] ?? '1';

if (($dirtyInput !== '0') && ($dirtyInput !== '1')) {
    throw new RuntimeException('Build workspace state must be explicitly 0 or 1.');
}

$dirty = $dirtyInput === '1';
$kind  = $argv[4] ?? 'runtime';

if (($revision !== 'workspace') && (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $revision) !== 1)) {
    throw new RuntimeException('Build revision must name a Git commit or explicit workspace input.');
}

if (($kind !== 'runtime') && ($kind !== 'binary')) {
    throw new RuntimeException('Expected runtime or binary manifest kind.');
}

$packages = [];
$names    = InstalledVersions::getInstalledPackages();
sort($names);

foreach ($names as $name) {
    if (($name === 'magicsunday/photo-renamer') || (InstalledVersions::getPrettyVersion($name) === null)) {
        continue;
    }

    $packages[] = ['name' => $name, 'version' => InstalledVersions::getPrettyVersion($name), 'reference' => InstalledVersions::getReference($name)];
}

$composer = new Process([PHP_BINARY, '/usr/bin/composer', '--version', '--no-ansi']);
$composer->setTimeout(30);
$composer->mustRun();

if (preg_match('/Composer version ([^\s]+)/', $composer->getOutput(), $composerVersion) !== 1) {
    throw new RuntimeException('Cannot identify the pinned Composer build tool.');
}

$extensions = get_loaded_extensions();
sort($extensions);
preg_match_all('/^FROM ([^\s]+@sha256:[a-f0-9]{64})/m', $filesystem->readFile($root . '/Dockerfile'), $images);

if (count($images[1]) !== 3) {
    throw new RuntimeException('Every base image must have a reviewed digest.');
}

$finder         = new ExecutableFinder();
$nativePackages = [];

if ($finder->find('apk') !== null) {
    $native = new Process(['apk', 'info', '-v']);
} elseif ($finder->find('dpkg-query') !== null) {
    $native = new Process(['dpkg-query', '-W', '-f=${Package}=${Version}\n']);
} else {
    throw new RuntimeException('Cannot identify the native build packages.');
}

$native->setTimeout(30);
$native->mustRun();
$nativePackages = explode("\n", trim($native->getOutput()));
sort($nativePackages);
$manifest = [
    'schema'                => 1,
    'kind'                  => $kind,
    'revision'              => $revision === 'workspace' ? null : $revision,
    'workspace_modified'    => $dirty || ($revision === 'workspace'),
    'source_sha256'         => publicSourceDigest($filesystem, $root),
    'composer_lock_sha256'  => hash('sha256', $filesystem->readFile($root . '/composer.lock')),
    'application_version'   => trim($filesystem->readFile($root . '/version')),
    'packages'              => $packages,
    'tools'                 => ['php' => PHP_VERSION, 'composer' => $composerVersion[1], 'php_extensions' => $extensions],
    'base_images'           => $images[1],
    'build_native_packages' => $nativePackages,
];

if ($kind === 'binary') {
    $bootstrapRoot                        = $argv[5] ?? '';
    $manifest['native_source_inputs']     = explode("\n", trim($filesystem->readFile($bootstrapRoot . '/.build/spc/native-inputs.sha256')));
    $manifest['bootstrap_receipt_sha256'] = hash('sha256', $filesystem->readFile($bootstrapRoot . '/.build/spc/bootstrap.sha256'));
    $spc                                  = new Process([$bootstrapRoot . '/.build/bin/spc', '--version']);
    $spc->setTimeout(30);
    $spc->mustRun();
    $manifest['tools']['spc'] = trim($spc->getOutput());
}

$filesystem->dumpFile($output, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Public build input manifest written.\n";
