<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

use function array_keys;
use function array_values;
use function basename;
use function count;
use function dirname;
use function file_exists;
use function glob;
use function hash_file;
use function implode;
use function is_dir;
use function is_file;
use function json_encode;
use function ksort;
use function pathinfo;
use function preg_grep;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_FILENAME;

require $argv[1];

$fixtures   = $argv[2];
$entry      = $argv[3];
$prefix     = isset($argv[4]) ? [$argv[4], $entry] : [$entry];
$filesystem = new Filesystem();
$workspace  = $filesystem->tempnam(sys_get_temp_dir(), 'renamer-command-contracts-');
$filesystem->remove($workspace);
$filesystem->mkdir([$workspace . '/home', $workspace . '/temp', $workspace . '/cwd']);
$environment = [
    'CACHE_DIR'                 => false,
    'HOME'                      => $workspace . '/home',
    'TMPDIR'                    => $workspace . '/temp',
    'PHP_MEMORY_LIMIT'          => '512M',
    'TIMEZONE'                  => 'UTC',
    'MAX_DATE_DRIFT'            => '0',
    'MERGE_THRESHOLD'           => false,
    'MAX_COMPARISON_PAIRS'      => '100000',
    'CANONICAL_FORMAT_PRIORITY' => false,
    'FILE_LINK_ROOT'            => false,
    'FILE_LINK_BASE'            => false,
    'FILE_LINK_PROTOCOL'        => false,
];

/**
 * @param bool   $condition Observed contract
 * @param string $message   Synthetic-case diagnostic
 */
function requireContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<string>                $prefix      Executable and optional interpreter
 * @param list<string>                $arguments   Actual CLI arguments
 * @param array<string, string|false> $environment Explicit child configuration; false removes inherited values
 *
 * @return Process Completed process with captured diagnostics
 */
function runCli(array $prefix, array $arguments, string $cwd, array $environment, int $expectedStatus = 0): Process
{
    $process = new Process([...$prefix, ...$arguments, '--no-ansi'], $cwd, $environment, "yes\nyes\n");
    $process->setTimeout(30.0);
    $process->run();
    $text = $process->getOutput() . $process->getErrorOutput();
    requireContract($process->getExitCode() === $expectedStatus, implode(' ', $arguments) . ': ' . $text);
    requireContract(!str_contains($text, 'Fatal error:') && !str_contains($text, 'internal corruption') && !str_contains($text, 'zlib: data error'), 'Archive/autoload failure: ' . $text);

    return $process;
}

/**
 * @return array<string, string> Relative filenames mapped to fresh byte hashes
 */
function mediaSnapshot(string $directory): array
{
    $snapshot = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $snapshot[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($snapshot);

    return $snapshot;
}

try {
    // Real cold reads must outnumber the descriptor budget; tiny smoke batches
    // cannot detect stream ownership cycles that wait for automatic collection.
    $pressureDirectory = $workspace . '/metadata-descriptor-pressure';
    $filesystem->mkdir($pressureDirectory);

    for ($index = 0; $index < 384; ++$index) {
        $filesystem->copy($fixtures . '/10-already-correct/2024-10-20_16-45-00-000.jpg', $pressureDirectory . '/' . sprintf('%04d.jpg', $index));
    }

    $pressureBefore = mediaSnapshot($pressureDirectory);
    $limitedPrefix = ['sh', '-c', 'set -e; ulimit -n 128; exec "$@"', 'metadata-descriptor-pressure', ...$prefix];
    runCli($limitedPrefix, ['rename:exif', $pressureDirectory, '--dry-run', '--show=E'], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($pressureDirectory) === $pressureBefore, 'Descriptor-pressure dry-run changed media');
    $filesystem->dumpFile($pressureDirectory . '/zz-corrupt.jpg', 'synthetic invalid media');
    $pressureBefore = mediaSnapshot($pressureDirectory);
    $pressureResult = runCli($limitedPrefix, ['rename:verify', $pressureDirectory, '--show=E'], $workspace . '/cwd', [...$environment, 'CACHE_DIR' => $workspace . '/fresh-pressure-cache']);
    requireContract(str_contains($pressureResult->getOutput(), 'zz-corrupt.jpg'), 'Late damaged input was not reported after descriptor-pressure reads');
    requireContract(!str_contains($pressureResult->getOutput() . $pressureResult->getErrorOutput(), 'No file descriptors'), 'Descriptor budget exhausted');
    requireContract(mediaSnapshot($pressureDirectory) === $pressureBefore, 'Descriptor-pressure verification changed media');
    echo 'Metadata descriptors: 384 cold files with a 128-descriptor limit, dry-run and late damaged-input diagnostics passed' . PHP_EOL;

    $renameCases = [
        ['rename:lower', 'PHOTO.JPG', 'photo.jpg', []],
        ['rename:pattern', 'PHOTO.jpeg', 'PHOTO.jpg', ['--pattern=/^(.+)\\.jpe?g$/', '--replacement=${1}.jpg']],
        ['rename:date', '2024-01-15.10-30-00_photo.jpg', '2024-01-15_10-30-00_photo.jpg', ['--pattern=/^{Y}-{m}-{d}[._]{H}-{i}-{s}(.+)$/']],
        ['rename:exif', 'CAMERA.JPG', '2024-10-20_16-45-00-000.jpg', []],
    ];

    foreach ($renameCases as [$command, $sourceName, $targetName, $options]) {
        foreach (['directory', 'file', 'relative-directory', 'relative-file', 'directory-slash'] as $mode) {
            $directory = $workspace . '/media/' . $command . '-' . $mode . '/nested with spaces';
            $filesystem->mkdir($directory);
            $source = $directory . '/' . $sourceName;
            $target = $directory . '/' . $targetName;
            $filesystem->copy($fixtures . '/10-already-correct/2024-10-20_16-45-00-000.jpg', $source);
            $singleFile = str_ends_with($mode, 'file');
            $input      = $singleFile ? $source : dirname($directory);

            if (str_starts_with($mode, 'relative-')) {
                $input = '../' . substr($input, strlen($workspace) + 1);
            } elseif ($mode === 'directory-slash') {
                $input .= '/';
            }
            $sibling = $directory . '/2024-03-04.05-06-07_UNSELECTED.jpeg';

            if ($singleFile) {
                $filesystem->copy($fixtures . '/10-already-correct/2024-10-20_16-45-00-000.jpg', $sibling);
            }
            $before  = mediaSnapshot($directory);
            $preview = runCli($prefix, [$command, $input, ...$options, '--dry-run', '--show=R'], $workspace . '/cwd', $environment);
            requireContract(!str_contains($preview->getOutput(), 'Verifying source file safety') && !str_contains($preview->getOutput(), 'Are you sure?'), $command . ' dry-run entered mutation startup');
            requireContract(mediaSnapshot($directory) === $before, $command . ' dry-run changed paths/bytes');
            $answer  = new InputStream();
            $decline = new Process([...$prefix, $command, $input, ...$options, '--no-ansi'], $workspace . '/cwd', $environment, $answer);
            $decline->setTimeout(30.0);

            try {
                $decline->start();
                requireContract($decline->waitUntil(static fn (): bool => str_contains($decline->getOutput(), 'Are you sure?')), $command . ' did not display confirmation before waiting for input');
                requireContract(!str_contains($decline->getOutput(), 'Verifying source file safety'), $command . ' began verification before the answer');
                $answer->write("no\n");
                $answer->close();
                $decline->wait();
            } finally {
                $decline->stop();
            }
            requireContract(($decline->getExitCode() === 1) && str_contains($decline->getOutput(), 'Are you sure?') && !str_contains($decline->getOutput(), 'Verifying source file safety'), $command . ' refusal entered safety verification');
            requireContract(mediaSnapshot($directory) === $before, $command . ' refusal changed paths/bytes');
            $confirmed = runCli($prefix, [$command, $input, ...$options], $workspace . '/cwd', $environment);
            $prompt    = strpos($confirmed->getOutput(), 'Are you sure?');
            $phase     = strpos($confirmed->getOutput(), 'Verifying source file safety');
            requireContract(($prompt !== false) && ($phase !== false) && ($prompt < $phase), $command . ' did not confirm before safety verification');
            $expectedCount = $singleFile ? 1 : count($before);
            $progressOutput = $confirmed->getOutput() . $confirmed->getErrorOutput();
            requireContract(str_contains($progressOutput, $expectedCount . '/' . $expectedCount) && str_contains($progressOutput, 'Remaining:'), $command . ' did not display counted verification progress');
            requireContract(!file_exists($source) && is_file($target), $command . ' did not perform the expected rename: ' . json_encode(array_keys(mediaSnapshot($directory)), JSON_THROW_ON_ERROR));
            requireContract(hash_file('sha256', $target) === $before[$sourceName], $command . ' changed media bytes');
            $after       = mediaSnapshot($directory);
            $repeatInput = $singleFile ? $target : $input;

            if ($mode === 'relative-file') {
                $repeatInput = '../' . substr($target, strlen($workspace) + 1);
            }
            runCli($prefix, [$command, $repeatInput, ...$options], $workspace . '/cwd', $environment);
            requireContract(mediaSnapshot($directory) === $after, $command . ' fixed-point repeat changed media');

            if ($singleFile) {
                requireContract(is_file($sibling) && (hash_file('sha256', $sibling) === $before[basename($sibling)]), 'Single-file run changed sibling');
            }
        }
        echo $command . ': preview, mutation, fixed-point repeat, absolute/relative directory/single-file and trailing-slash paths passed' . PHP_EOL;
    }

    $directory = $workspace . '/media/live photo';
    $filesystem->mirror($fixtures . '/04-live-photo-pair', $directory);
    $before = mediaSnapshot($directory);
    runCli($prefix, ['rename:exif', $directory, '--dry-run'], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $before, 'Live Photo preview changed media');
    runCli($prefix, ['rename:exif', $directory], $workspace . '/cwd', $environment);
    $after = mediaSnapshot($directory);
    requireContract(count($after) === 2, 'Live Photo lost a component');
    $names = array_keys($after);
    requireContract(pathinfo($names[0], PATHINFO_FILENAME) === pathinfo($names[1], PATHINFO_FILENAME), 'Live Photo companion did not inherit the still basename');
    $oldHashes = array_values($before);
    $newHashes = array_values($after);
    sort($oldHashes);
    sort($newHashes);
    requireContract($oldHashes === $newHashes, 'Live Photo rename changed media bytes');
    runCli($prefix, ['rename:exif', $directory], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $after, 'Live Photo repeat changed names/bytes');
    echo 'rename:exif: real JPG/MOV pairing, component bytes and repeated execution passed' . PHP_EOL;

    $directory = $workspace . '/media/hash';
    $filesystem->mkdir($directory);

    foreach (['a.jpg', 'b.jpg'] as $name) {
        $filesystem->copy($fixtures . '/10-already-correct/2024-10-20_16-45-00-000.jpg', $directory . '/' . $name);
    }
    $before = mediaSnapshot($directory);
    runCli($prefix, ['rename:hash', $directory, '--dry-run'], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $before, 'hash preview changed files');
    runCli($prefix, ['rename:hash', $directory], $workspace . '/cwd', $environment);
    $after = mediaSnapshot($directory);
    requireContract(count($after) === 2 && count(preg_grep('/-duplicate-001\\.jpg$/', array_keys($after))) === 1, 'hash did not group identical files');
    requireContract(array_values($after) === array_values($before), 'hash changed duplicate bytes');
    runCli($prefix, ['rename:hash', $directory], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $after, 'hash repeat changed files');
    echo 'rename:hash: preview, actual grouping, byte preservation and repeat passed' . PHP_EOL;

    foreach (['quarantine', 'delete'] as $action) {
        $directory = $workspace . '/media/dedup-' . $action;
        $filesystem->mkdir($directory);
        $filesystem->dumpFile($directory . '/capture.jpg', 'identical synthetic media bytes');
        $filesystem->copy($directory . '/capture.jpg', $directory . '/capture-duplicate-001.jpg');
        $options = $action === 'delete' ? ['--delete'] : ['--target=review copies'];
        $before  = mediaSnapshot($directory);
        runCli($prefix, ['rename:dedup', $directory, ...$options, '--dry-run'], $workspace . '/cwd', $environment);
        requireContract(mediaSnapshot($directory) === $before, 'dedup preview changed files');
        runCli($prefix, ['rename:dedup', $directory, ...$options], $workspace . '/cwd', $environment);
        requireContract($filesystem->readFile($directory . '/capture.jpg') === 'identical synthetic media bytes', 'dedup changed original');
        requireContract(!file_exists($directory . '/capture-duplicate-001.jpg'), 'dedup did not act on duplicate');

        if ($action === 'quarantine') {
            requireContract($filesystem->readFile($directory . '/review copies/capture-duplicate-001.jpg') === 'identical synthetic media bytes', 'quarantine bytes missing');
        } else {
            requireContract(count(mediaSnapshot($directory)) === 1, 'verified deletion left duplicate');
        }
    }
    // Different bytes must remain protected even when the filename claims duplicate status.
    $filesystem->dumpFile($directory . '/capture-duplicate-002.jpg', 'different synthetic media bytes');
    $before = mediaSnapshot($directory);
    runCli($prefix, ['rename:dedup', $directory, '--delete'], $workspace . '/cwd', $environment, 1);
    requireContract(mediaSnapshot($directory) === $before, 'dedup deleted an unverified original/duplicate');
    echo 'rename:dedup: preview, quarantine, verified delete and different-byte refusal passed' . PHP_EOL;

    $directory = $workspace . '/media/write date';
    $filesystem->mkdir($directory);
    $source = $directory . '/2024-02-03_04-05-06-000.jpg';
    $filesystem->copy($fixtures . '/07-no-metadata/screenshot.jpg', $source);
    $before = mediaSnapshot($directory);
    $verify = runCli($prefix, ['rename:verify', $source, '--detail', '--show=nodata'], $workspace . '/cwd', $environment);
    requireContract(str_contains($verify->getOutput(), 'rename:write-date --reason=nodata'), 'verify did not diagnose missing metadata');
    requireContract(mediaSnapshot($directory) === $before, 'verify changed media');
    runCli($prefix, ['rename:write-date', $source, '--reason=nodata', '--dry-run'], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $before, 'write-date preview changed bytes');
    runCli($prefix, ['rename:write-date', $source, '--reason=nodata'], $workspace . '/cwd', $environment);
    $readDate = new Process(['exiftool', '-s3', '-DateTimeOriginal', $source]);
    $readDate->mustRun();
    requireContract(trim($readDate->getOutput()) === '2024:02:03 04:05:06', 'write-date did not write actual EXIF timestamp');
    $after = mediaSnapshot($directory);
    requireContract(count($after) === 1 && $after !== $before, 'write-date did not update only the intended file');
    runCli($prefix, ['rename:write-date', $source, '--reason=nodata'], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $after, 'write-date repeat changed valid metadata');
    $verify = runCli($prefix, ['rename:verify', $source, '--detail', '--show=nodata'], $workspace . '/cwd', $environment);
    requireContract(!str_contains($verify->getOutput(), 'rename:write-date --reason=nodata'), 'verify still reports missing date after repair');
    requireContract(mediaSnapshot($directory) === $after, 'verify changed repaired media');
    echo 'rename:verify / rename:write-date: real diagnosis, read-only state, EXIF write and repeat passed' . PHP_EOL;

    $defaultBase = str_ends_with($entry, '/src/Renamer.php') ? dirname($entry, 2) . '/.build/cache' : $workspace . '/home/.cache/renamer';
    requireContract(is_dir($defaultBase . '/locks'), 'Default lock state is not on the host');
    requireContract(glob($defaultBase . '/private-*/mutation-*.jsonl') !== [], 'Default journal state missing');

    foreach (['absolute', 'relative', 'empty', 'no-home'] as $mode) {
        $directory = $workspace . '/media/state-' . $mode;
        $filesystem->copy($fixtures . '/10-already-correct/2024-10-20_16-45-00-000.jpg', $directory . '/SOURCE.JPG');
        $env = $environment;

        if ($mode === 'absolute') {
            $env['CACHE_DIR'] = $workspace . '/explicit-state';
            $base             = $env['CACHE_DIR'];
        } elseif ($mode === 'relative') {
            $env['CACHE_DIR'] = 'relative state';
            $base             = $workspace . '/cwd/relative state';
        } elseif ($mode === 'empty') {
            $env['CACHE_DIR'] = '';
            $base             = $defaultBase;
        } else {
            $env['HOME'] = false;
            $base        = str_ends_with($entry, '/src/Renamer.php') ? $defaultBase : $workspace . '/temp/renamer-cache';
        }
        runCli($prefix, ['rename:exif', $directory], $workspace . '/cwd', $env);
        requireContract(is_file($directory . '/2024-10-20_16-45-00-000.jpg') && is_dir($base . '/locks'), $mode . ' state/mutation failed');
        requireContract(glob($base . '/private-*/mutation-*.jsonl') !== [], $mode . ' journal missing');
        requireContract(glob($base . '/private-*/metadata-cache.json') !== [], $mode . ' metadata cache did not share the state base');
    }
    $filesystem->dumpFile($workspace . '/blocked-state', 'synthetic non-directory');
    $env              = $environment;
    $env['CACHE_DIR'] = $workspace . '/blocked-state';
    $before           = mediaSnapshot($directory);
    runCli($prefix, ['rename:lower', $directory], $workspace . '/cwd', $env, 1);
    requireContract(mediaSnapshot($directory) === $before, 'State failure changed media');
    echo 'State: unset CACHE_DIR, HOME/default/temp, absolute/relative overrides and I/O failure passed' . PHP_EOL;
} finally {
    $filesystem->remove($workspace);
}
