<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use DateTimeImmutable;
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
use function hash;
use function preg_match;
use function str_replace;
use function substr_count;
use function hash_file;
use function implode;
use function in_array;
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
    requireContract(preg_match('/^(?:PHP )?(?:Warning|Notice|Deprecated|Fatal error|Parse error): /m', $text) !== 1, 'PHP runtime diagnostic: ' . $text);
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


/**
 * Runs the same finite-capacity contract for source, PHAR and native artifacts.
 *
 * @param list<string> $prefix Executable and optional interpreter
 * @param array<string, string|false> $environment Child configuration
 */
function checkCollectionCapacity(array $prefix, string $fixtures, Filesystem $filesystem, string $workspace, array $environment): void
{
    $media = $workspace . '/capacity media';
    $template = $filesystem->readFile($fixtures . '/10-already-correct/2024-10-20_16-45-00-000.jpg');
    requireContract(substr_count($template, '2024:10:20 16:45:00') > 0, 'Capacity fixture date missing');
    $start = new DateTimeImmutable('2024-10-20T16:45:00+00:00');
    $expected = [];

    for ($index = 0; $index < 6000; ++$index) {
        $date = $start->modify('+' . $index . ' seconds');
        $bytes = str_replace('2024:10:20 16:45:00', $date->format('Y:m:d H:i:s'), $template);
        $filesystem->dumpFile($media . '/' . sprintf('CAMERA-%05d.jpg', $index), $bytes);
        $expected[$date->format('Y-m-d_H-i-s') . '-000.jpg'] = hash('sha256', $bytes);
    }

    foreach (['preview', 'rename', 'repeat', 'warm-repeat'] as $mode) {
        $options = $mode === 'preview' ? [] : ['--apply'];
        $result = runCli($prefix, ['rename:exif', $media, '--show=E', ...$options], $workspace . '/cwd', [
            ...$environment,
            'PHP_MEMORY_LIMIT' => '96M',
            'CACHE_DIR' => $workspace . ($mode === 'preview' ? '/capacity-preview-state' : '/capacity-state'),
        ]);
        requireContract(preg_match('/Scanned files\s+6000\b/', $result->getOutput()) === 1, 'Capacity scan incomplete: ' . $mode);
        $observed = glob($media . '/*.jpg');
        requireContract(($observed !== false) && (count($observed) === 6000), 'Capacity files missing: ' . $mode);
        $index = 0;

        foreach ($expected as $name => $digest) {
            $actualName = $mode === 'preview' ? sprintf('CAMERA-%05d.jpg', $index) : $name;
            requireContract(hash_file('sha256', $media . '/' . $actualName) === $digest, 'Capacity name/bytes changed: ' . $mode);
            ++$index;
        }
    }

    echo 'Collection capacity: 6000 distinct captures at 96 MiB, cold preview, confirmed rename and cold/warm retained-cache repeats passed' . PHP_EOL;
}

try {
    $limitedPrefix = ['sh', '-c', 'set -e; ulimit -n 128; exec "$@"', 'metadata-descriptor-pressure', ...$prefix];
    checkCollectionCapacity($limitedPrefix, $fixtures, $filesystem, $workspace, $environment);

    if (($argv[5] ?? null) === 'capacity-only') {
        return;
    }

    // The mode contract is shared by every media-writing entry point, including
    // destructive selectors. Preview may cache analysis but never opens mutation state.
    foreach ([
        ['rename:lower', 'PHOTO.JPG', []],
        ['rename:pattern', 'PHOTO.jpeg', ['--pattern=/^(.+)\\.jpeg$/', '--replacement=${1}.jpg']],
        ['rename:date', '2024-01-15.10-30-00_photo.jpg', ['--pattern=/^{Y}-{m}-{d}[._]{H}-{i}-{s}(.+)$/']],
        ['rename:exif', 'CAMERA.JPG', []],
        ['rename:hash', 'CAMERA.JPG', []],
        ['rename:dedup', 'capture-duplicate-001.jpg', ['--delete']],
        ['rename:write-date', '2024-02-03_04-05-06-000.jpg', ['--reason=nodata', '--force']],
    ] as [$command, $name, $options]) {
        $directory = $workspace . '/mode/' . $command;
        $fixture = $command === 'rename:write-date' ? '/07-no-metadata/screenshot.jpg' : '/10-already-correct/2024-10-20_16-45-00-000.jpg';
        $filesystem->copy($fixtures . $fixture, $directory . '/' . $name);

        if ($command === 'rename:dedup') {
            $filesystem->copy($directory . '/' . $name, $directory . '/capture.jpg');
        }

        $before = mediaSnapshot($directory);
        $modeEnvironment = [...$environment, 'CACHE_DIR' => $workspace . '/mode-state/' . $command];
        $preview = runCli($prefix, [$command, $directory, ...$options, '--no-interaction'], $workspace . '/cwd', $modeEnvironment);
        requireContract(str_contains($preview->getOutput(), 'Use --apply') && !str_contains($preview->getOutput(), 'Are you sure?') && !str_contains($preview->getOutput(), 'Verifying source file safety'), $command . ' did not clearly default to preview');
        requireContract(mediaSnapshot($directory) === $before, $command . ' default preview changed media');
        requireContract(!is_dir($modeEnvironment['CACHE_DIR'] . '/locks') && glob($modeEnvironment['CACHE_DIR'] . '/private-*/mutation-*.jsonl') === [], $command . ' default preview entered mutation state');
        $help = runCli($prefix, ['help', $command], $workspace . '/cwd', $modeEnvironment);
        requireContract(str_contains($help->getOutput(), '--apply') && !str_contains($help->getOutput(), '--dry-run'), $command . ' help exposes the wrong mode options');

        foreach ([['--dry-run'], ['-d'], ['--apply', '--dry-run'], ['--apply', '-d']] as $removedOptions) {
            runCli($prefix, [$command, $directory, ...$options, ...$removedOptions], $workspace . '/cwd', $modeEnvironment, 1);
            requireContract(mediaSnapshot($directory) === $before, $command . ' removed option changed media');
            requireContract(!is_dir($modeEnvironment['CACHE_DIR'] . '/locks') && glob($modeEnvironment['CACHE_DIR'] . '/private-*/mutation-*.jsonl') === [], $command . ' rejected option entered mutation state');
        }

        $expectedStatus = in_array($command, ['rename:dedup', 'rename:write-date'], true) ? 0 : 1;
        runCli($prefix, [$command, $directory, ...$options, '--apply', '--no-interaction'], $workspace . '/cwd', $modeEnvironment, $expectedStatus);
        requireContract(mediaSnapshot($directory) === $before, $command . ' non-interactive apply bypassed confirmation');
    }
    $verifyHelp = runCli($prefix, ['help', 'rename:verify'], $workspace . '/cwd', $environment);
    requireContract(!str_contains($verifyHelp->getOutput(), '--apply'), 'Read-only verify exposed apply');
    runCli($prefix, ['rename:verify', $directory, '--apply'], $workspace . '/cwd', $environment, 1);
    requireContract(mediaSnapshot($directory) === $before, 'Verify apply rejection changed media');
    echo 'All seven mutating commands: default preview, explicit apply refusal, removed flags and mutation-scope isolation passed' . PHP_EOL;

    // Parser families have distinct resource ownership; both cold reads and
    // cached reads must scan beyond the descriptor budget without changing media.
    foreach ([
        'jpg' => '10-already-correct/2024-10-20_16-45-00-000.jpg',
        'heic' => '14-heic-image/IMG_0042.heic',
        'mov' => '33-mov-with-timezone/clip.mov',
        'avi' => '34-avi-with-date/clip.avi',
    ] as $extension => $fixture) {
        $directory = $workspace . '/metadata-pressure-' . $extension;
        $filesystem->mkdir($directory);

        for ($index = 0; $index < 384; ++$index) {
            $filesystem->copy($fixtures . '/' . $fixture, $directory . '/' . sprintf('%04d.', $index) . $extension);
        }
        $filesystem->dumpFile($directory . '/zz-corrupt.jpg', 'synthetic invalid media');
        $before = mediaSnapshot($directory);
        $pressureEnvironment = [...$environment, 'CACHE_DIR' => $workspace . '/pressure-cache-' . $extension];

        foreach (['cold', 'warm'] as $phase) {
            $result = runCli($limitedPrefix, ['rename:verify', $directory, '--show=E'], $workspace . '/cwd', $pressureEnvironment);
            requireContract(preg_match('/Scanned files\s+385\b/', $result->getOutput()) === 1, $extension . ' ' . $phase . ' descriptor scan incomplete');
            requireContract(str_contains($result->getOutput(), 'zz-corrupt.jpg'), $extension . ' ' . $phase . ' damaged input was not reported');
            requireContract(mediaSnapshot($directory) === $before, $extension . ' ' . $phase . ' verification changed names/bytes');
        }

        if ($extension === 'jpg') {
            // EXIF sorts source paths, so this fresh-cache damaged read really
            // occurs after all 384 valid inputs, independently of readdir order.
            $late = runCli($limitedPrefix, ['rename:exif', $directory, '--show=E'], $workspace . '/cwd', [...$environment, 'CACHE_DIR' => $workspace . '/late-pressure-cache'], 1);
            requireContract(str_contains($late->getOutput(), 'zz-corrupt.jpg'), 'Sorted late damaged input was not reported');
            requireContract(mediaSnapshot($directory) === $before, 'Late damaged-input preview changed names/bytes');
        }
        echo 'Metadata descriptors: ' . $extension . ', 384 valid files plus damaged input, cold/warm at 128 descriptors passed' . PHP_EOL;
    }

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
            $preview = runCli($prefix, [$command, $input, ...$options, '--show=R'], $workspace . '/cwd', $environment);
            requireContract(!str_contains($preview->getOutput(), 'Verifying source file safety') && !str_contains($preview->getOutput(), 'Are you sure?'), $command . ' dry-run entered mutation startup');
            requireContract(mediaSnapshot($directory) === $before, $command . ' dry-run changed paths/bytes');
            $answer  = new InputStream();
            $decline = new Process([...$prefix, $command, $input, ...$options, '--apply', '--no-ansi'], $workspace . '/cwd', $environment, $answer);
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
            $confirmed = runCli($prefix, [$command, $input, ...$options, '--apply'], $workspace . '/cwd', $environment);
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
            runCli($prefix, [$command, $repeatInput, ...$options, '--apply'], $workspace . '/cwd', $environment);
            requireContract(mediaSnapshot($directory) === $after, $command . ' fixed-point repeat changed media');

            if ($singleFile) {
                requireContract(is_file($sibling) && (hash_file('sha256', $sibling) === $before[basename($sibling)]), 'Single-file run changed sibling');
            }
        }
        echo $command . ': preview, mutation, fixed-point repeat, absolute/relative directory/single-file and trailing-slash paths passed' . PHP_EOL;
    }

    // Execute both existing real-media fixtures: one primary pair and a
    // capture with independently named variants. Plan-only checks miss false
    // partial errors raised after every physical rename already succeeded.
    foreach (['04-live-photo-pair' => 2, '29-livephoto-edit-duplicate' => 6] as $fixture => $expectedCount) {
        $directory = $workspace . '/media/live photo ' . $fixture;
        $filesystem->mirror($fixtures . '/' . $fixture, $directory);
        $before = mediaSnapshot($directory);
        runCli($prefix, ['rename:exif', $directory], $workspace . '/cwd', $environment);
        requireContract(mediaSnapshot($directory) === $before, 'Live Photo preview changed media: ' . $fixture);
        $renamed = runCli($prefix, ['rename:exif', $directory, '--apply'], $workspace . '/cwd', $environment);
        requireContract(!str_contains($renamed->getOutput() . $renamed->getErrorOutput(), 'Partial Live Photo groups'), 'Planned variant reported as incomplete: ' . $fixture);
        $after = mediaSnapshot($directory);
        requireContract(count($after) === $expectedCount, 'Live Photo lost a component: ' . $fixture);

        if ($fixture === '04-live-photo-pair') {
            $names = array_keys($after);
            requireContract(pathinfo($names[0], PATHINFO_FILENAME) === pathinfo($names[1], PATHINFO_FILENAME), 'Live Photo companion did not inherit the still basename');
        }

        $oldHashes = array_values($before);
        $newHashes = array_values($after);
        sort($oldHashes);
        sort($newHashes);
        requireContract($oldHashes === $newHashes, 'Live Photo rename changed media bytes: ' . $fixture);
        runCli($prefix, ['rename:exif', $directory, '--apply'], $workspace . '/cwd', $environment);
        requireContract(mediaSnapshot($directory) === $after, 'Live Photo repeat changed names/bytes: ' . $fixture);
    }
    echo 'rename:exif: real JPG/MOV pairing, planned companion variants, component bytes and repeated execution passed' . PHP_EOL;

    // A real unprivileged directory-permission failure must leave the MOV
    // intact after its still moved, with actionable component diagnostics.
    $directory = $workspace . '/media/partial permission pair';
    $stillDirectory = $directory . '/stills';
    $videoDirectory = $directory . '/videos';
    $stillSource = $stillDirectory . '/CAMERA.jpg';
    $videoSource = $videoDirectory . '/CAMERA.mov';
    $filesystem->copy($fixtures . '/04-live-photo-pair/IMG_0001.jpg', $stillSource);
    $filesystem->copy($fixtures . '/04-live-photo-pair/IMG_0001.mov', $videoSource);
    $stillHash = hash_file('sha256', $stillSource);
    $videoHash = hash_file('sha256', $videoSource);
    $filesystem->chmod($videoDirectory, 0o555);

    try {
        $partial = runCli($prefix, ['rename:exif', $directory, '--apply'], $workspace . '/cwd', $environment, 1);
        $details = $partial->getOutput() . $partial->getErrorOutput();
        requireContract(preg_match('/Renamed\s+1\b/', $details) === 1, 'Partial run did not retain its one successful move');
        requireContract(preg_match('/Partial Live Photo groups\s+1\b/', $details) === 1, 'Partial run did not report the incomplete pair');
        requireContract(str_contains($details, 'companion: Failed:') && str_contains($details, 'Current: ' . $videoSource) && str_contains($details, 'Planned: ') && str_contains($details, 'Journal: '), 'Partial run omitted component paths, concrete status or journal');
        $stills = glob($stillDirectory . '/*.jpg');
        requireContract(($stills !== false) && (count($stills) === 1) && !file_exists($stillSource) && (hash_file('sha256', $stills[0]) === $stillHash), 'Partial run lost or misreported the moved still');
        requireContract(hash_file('sha256', $videoSource) === $videoHash, 'Partial run changed or removed the failed companion');
    } finally {
        $filesystem->chmod($videoDirectory, 0o755);
    }
    echo 'rename:exif: real companion permission failure, retained bytes and actionable partial diagnostics passed' . PHP_EOL;

    $directory = $workspace . '/media/hash';
    $filesystem->mkdir($directory);

    foreach (['a.jpg', 'b.jpg'] as $name) {
        $filesystem->copy($fixtures . '/10-already-correct/2024-10-20_16-45-00-000.jpg', $directory . '/' . $name);
    }
    $before = mediaSnapshot($directory);
    runCli($prefix, ['rename:hash', $directory], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $before, 'hash preview changed files');
    runCli($prefix, ['rename:hash', $directory, '--apply'], $workspace . '/cwd', $environment);
    $after = mediaSnapshot($directory);
    requireContract(count($after) === 2 && count(preg_grep('/-duplicate-001\\.jpg$/', array_keys($after))) === 1, 'hash did not group identical files');
    requireContract(array_values($after) === array_values($before), 'hash changed duplicate bytes');
    runCli($prefix, ['rename:hash', $directory, '--apply'], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $after, 'hash repeat changed files');
    echo 'rename:hash: preview, actual grouping, byte preservation and repeat passed' . PHP_EOL;

    foreach (['quarantine', 'delete'] as $action) {
        $directory = $workspace . '/media/dedup-' . $action;
        $filesystem->mkdir($directory);
        $filesystem->dumpFile($directory . '/capture.jpg', 'identical synthetic media bytes');
        $filesystem->copy($directory . '/capture.jpg', $directory . '/capture-duplicate-001.jpg');
        $options = $action === 'delete' ? ['--delete'] : ['--target=review copies'];
        $before  = mediaSnapshot($directory);
        runCli($prefix, ['rename:dedup', $directory, ...$options], $workspace . '/cwd', $environment);
        requireContract(mediaSnapshot($directory) === $before, 'dedup preview changed files');
        runCli($prefix, ['rename:dedup', $directory, ...$options, '--apply'], $workspace . '/cwd', $environment);
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
    runCli($prefix, ['rename:dedup', $directory, '--delete', '--apply'], $workspace . '/cwd', $environment, 1);
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
    runCli($prefix, ['rename:write-date', $source, '--reason=nodata'], $workspace . '/cwd', $environment);
    requireContract(mediaSnapshot($directory) === $before, 'write-date preview changed bytes');
    runCli($prefix, ['rename:write-date', $source, '--reason=nodata', '--apply'], $workspace . '/cwd', $environment);
    $readDate = new Process(['exiftool', '-s3', '-DateTimeOriginal', $source]);
    $readDate->mustRun();
    requireContract(trim($readDate->getOutput()) === '2024:02:03 04:05:06', 'write-date did not write actual EXIF timestamp');
    $after = mediaSnapshot($directory);
    requireContract(count($after) === 1 && $after !== $before, 'write-date did not update only the intended file');
    runCli($prefix, ['rename:write-date', $source, '--reason=nodata', '--apply'], $workspace . '/cwd', $environment);
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
        runCli($prefix, ['rename:exif', $directory, '--apply'], $workspace . '/cwd', $env);
        requireContract(is_file($directory . '/2024-10-20_16-45-00-000.jpg') && is_dir($base . '/locks'), $mode . ' state/mutation failed');
        requireContract(glob($base . '/private-*/mutation-*.jsonl') !== [], $mode . ' journal missing');
        requireContract(glob($base . '/private-*/metadata-cache.json') !== [], $mode . ' metadata cache did not share the state base');
    }
    $filesystem->dumpFile($workspace . '/blocked-state', 'synthetic non-directory');
    $env              = $environment;
    $env['CACHE_DIR'] = $workspace . '/blocked-state';
    $before           = mediaSnapshot($directory);
    runCli($prefix, ['rename:lower', $directory, '--apply'], $workspace . '/cwd', $env, 1);
    requireContract(mediaSnapshot($directory) === $before, 'State failure changed media');
    echo 'State: unset CACHE_DIR, HOME/default/temp, absolute/relative overrides and I/O failure passed' . PHP_EOL;
} finally {
    $filesystem->remove($workspace);
}
