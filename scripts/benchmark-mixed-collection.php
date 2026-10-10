<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function bin2hex;
use function count;
use function dirname;
use function disk_free_space;
use function disk_total_space;
use function error_get_last;
use function filter_var;
use function getenv;
use function getrusage;
use function hrtime;
use function in_array;
use function intdiv;
use function json_decode;
use function json_encode;
use function max;
use function memory_get_peak_usage;
use function pack;
use function printf;
use function random_bytes;
use function register_shutdown_function;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr_count;
use function trim;
use function usleep;

use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const FILTER_VALIDATE_INT;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

// The production image supplies code/vendor/config, while only scripts are
// mounted read-only. Development also resolves this same script against /app.
$project = getenv('BENCHMARK_PROJECT_ROOT') ?: dirname(__DIR__);
require $project . '/.build/vendor/autoload.php';
require __DIR__ . '/BenchmarkProgressReporter.php';

/**
 * Rejects failed semantic checks explicitly even when PHP assertions are off.
 *
 * @param bool   $condition Required measured or semantic outcome
 * @param string $message   Non-sensitive failure description
 *
 * @return void
 */
function requireProfileInvariant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Builds minimal TIFF/Apple MakerNote metadata without copying camera metadata.
 * Offsets are fixed for the one date and one 36-character synthetic identifier.
 *
 * @param string $identifier Fixed-width synthetic Apple content identifier
 * @param string $date       EXIF date in its fixed-width 19-character format
 *
 * @return string Complete EXIF profile accepted by JPEG and HEIC encoders
 */
function syntheticExif(string $identifier, string $date): string
{
    $maker = "Apple iOS\0\0\1MM" . pack('n', 1)
        . pack('nnNN', 0x0011, 2, 37, 32) . pack('N', 0) . $identifier . "\0";

    return "Exif\0\0II" . pack('vV', 42, 8) . pack('v', 2)
        . pack('vvVV', 0x010F, 2, 6, 38) . pack('vvVV', 0x8769, 4, 1, 44)
        . pack('V', 0) . "Apple\0" . pack('v', 2)
        . pack('vvVV', 0x9003, 2, 20, 74)
        . pack('vvVV', 0x927C, 7, strlen($maker), 94)
        . pack('V', 0) . $date . "\0" . $maker;
}

$mode  = $argv[1] ?? 'profile';
$count = filter_var($argv[2] ?? '70000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 4, 'max_range' => 100000]]);

if (($count === false) || (($count % 4) !== 0)) {
    throw new InvalidArgumentException('File count must be a multiple of four between 4 and 100000.');
}
$filesystem = new Filesystem();

if ($mode === 'worker') {
    $workspace = $argv[3] ?? '';
    $phase     = $argv[4] ?? '';
    requireProfileInvariant($filesystem->readFile($workspace . '/synthetic.marker') === "photo-renamer-synthetic-profile\n", 'Refusing a non-synthetic workspace.');
    requireProfileInvariant(($phase === 'cold') || ($phase === 'warm') || ($phase === 'mutate') || ($phase === 'idempotency'), 'Unknown profile phase.');
    $start = hrtime(true);
    // Free a small emergency reserve before reporting a fatal worker failure.
    // Failed phases remain failures; an OOM is never counted as completed work.
    $emergencyReserve = str_repeat('x', 65536);
    register_shutdown_function(static function () use (&$emergencyReserve, $phase, $count, $start): void {
        $emergencyReserve = '';
        $error            = error_get_last();

        if (($error !== null) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $usage = getrusage();
            printf("%s\n", json_encode(['phase' => $phase, 'files' => $count, 'completed' => false, 'seconds' => (hrtime(true) - $start) / 1e9, 'php_peak_bytes' => memory_get_peak_usage(true), 'peak_rss_bytes' => $usage['ru_maxrss'] * 1024, 'fatal_type' => $error['type']], JSON_THROW_ON_ERROR));
        }
    });
    $container = new ContainerBuilder();
    new YamlFileLoader($container, new FileLocator($project . '/config'))->load('Services.yaml');
    $container->register(SymfonyStyle::class)->setPublic(true)->setSynthetic(true);
    $container->register(ProgressReporterInterface::class, BenchmarkProgressReporter::class)->setPublic(true);

    foreach ([AssetGroupPipeline::class, FileSystemService::class, ExecutionPlanBuilder::class, ExecutionPlanExecutor::class, ExifMetadataProvider::class, CanonicalScorer::class] as $service) {
        $container->getDefinition($service)->setPublic(true);
    }
    $container->compile(true);
    $container->set(SymfonyStyle::class, new SymfonyStyle(new ArrayInput([]), new NullOutput()));
    $media    = $workspace . '/media';
    $storage  = new PrivateCacheStorage($filesystem);
    $cache    = new MetadataCache($storage->directory($workspace . '/cache') . '/metadata.json', $storage);
    $provider = $container->get(ExifMetadataProvider::class);
    requireProfileInvariant($provider instanceof ExifMetadataProvider, 'Invalid metadata provider.');
    $provider->setDefaultTimezone(new DateTimeZone('UTC'));
    $provider->setCache($cache);
    $scorer = $container->get(CanonicalScorer::class);
    requireProfileInvariant($scorer instanceof CanonicalScorer, 'Invalid scorer.');
    $scorer->setSourceDirectory($media);
    $files    = $container->get(FileSystemService::class);
    $pipeline = $container->get(AssetGroupPipeline::class);
    $builder  = $container->get(ExecutionPlanBuilder::class);
    $executor = $container->get(ExecutionPlanExecutor::class);
    requireProfileInvariant(($files instanceof FileSystemService) && ($pipeline instanceof AssetGroupPipeline) && ($builder instanceof ExecutionPlanBuilder) && ($executor instanceof ExecutionPlanExecutor), 'Invalid pipeline wiring.');
    $result = $pipeline->run($files->createFileIterator($media), new ExifDateFilenameStrategy('Y-m-d_H-i-s-v', $provider), new TargetBasenameStrategy(), $media, true);
    requireProfileInvariant($result->validationResult->isValid() && ($result->context->getSkippedFiles() === []), 'Real media parsing or plan validation failed.');
    $plan = $builder->build($result->groups, $result->context);
    requireProfileInvariant(($plan->totalItemCount() === $count) && ($plan->groupCount() === intdiv($count, 2)) && ($plan->livePhotoGroupCount() === intdiv($count, 2)), 'Live Photo pairing is incomplete.');

    if ($plan->nonExecutableItemCount() > 0) {
        foreach ($plan->groups as $plannedGroup) {
            foreach ($plannedGroup->items as $plannedItem) {
                if (!$plannedItem->isExecutable && !$plannedItem->isNoOp) {
                    throw new RuntimeException('A unique synthetic item was blocked: ' . json_encode($plannedItem, JSON_THROW_ON_ERROR));
                }
            }
        }
    }

    $identifiers  = [];
    $formatCounts = ['jpg' => 0, 'heic' => 0, 'mov' => 0];

    foreach ($result->groups as $group) {
        requireProfileInvariant(!$group->isClassificationDegraded(), 'Synthetic group classification degraded.');
        $canonical = $group->getCanonical();
        requireProfileInvariant($canonical !== null, 'Missing canonical still.');
        requireProfileInvariant($canonical->contentIdentifier !== null, 'Native still metadata did not expose its synthetic content ID.');
        requireProfileInvariant(!isset($identifiers[$canonical->contentIdentifier]), 'Capture groups did not retain distinct synthetic IDs.');
        $identifiers[$canonical->contentIdentifier] = true;
        $expected                                   = $canonical->metadata?->getCaptureDateTime()?->format('Y-m-d_H-i-s-v');
        requireProfileInvariant($expected !== null, 'Missing real parsed capture date.');

        foreach ($group->getItems() as $item) {
            requireProfileInvariant($item->contentIdentifier === $canonical->contentIdentifier, 'A mismatched Live Photo ID was joined.');
            $extension = $item->extension();
            requireProfileInvariant(isset($formatCounts[$extension]), 'Unexpected synthetic media format.');
            ++$formatCounts[$extension];

            if ($extension === 'mov') {
                requireProfileInvariant($item->metadata?->getVideoDurationSeconds() === 1.0, 'Native metadata did not expose the expected common video duration.');
            }
            requireProfileInvariant($item->proposedName === $media . '/' . $expected . '.' . $item->extension(), 'Unexpected still/companion naming result.');
        }
    }
    requireProfileInvariant((count($identifiers) === intdiv($count, 2)) && ($formatCounts === ['jpg' => intdiv($count, 4), 'heic' => intdiv($count, 4), 'mov' => intdiv($count, 2)]), 'The measured native media mix is incomplete.');

    if ($phase === 'mutate') {
        $executed = $executor->executePlan($plan);
        requireProfileInvariant(($executed->executedMoves === $count) && ($executed->runtimeErrors === 0) && ($executed->runtimeFallbacks === 0), 'Synthetic media mutation was incomplete.');
    } elseif ($phase === 'idempotency') {
        requireProfileInvariant(($plan->noOpItemCount() === $count) && ($plan->executableItemCount() === 0), 'Second processing changed previously correct names.');
    }
    $cache->flush();
    $reporter = $container->get(ProgressReporterInterface::class);
    requireProfileInvariant($reporter instanceof BenchmarkProgressReporter, 'Invalid benchmark observer.');
    // Every known LP identifier is distinct; equal video streams cannot override it.
    requireProfileInvariant($reporter->comparisonPlans === 0, 'Distinct known IDs created an unnecessary pair plan.');
    $usage = getrusage();
    printf("%s\n", json_encode([
        'phase'            => $phase, 'files' => $count, 'groups' => $plan->groupCount(), 'live_photo_groups' => $plan->livePhotoGroupCount(),
        'seconds'          => (hrtime(true) - $start) / 1e9, 'php_peak_bytes' => memory_get_peak_usage(true), 'peak_rss_bytes' => $usage['ru_maxrss'] * 1024,
        'comparison_plans' => $reporter->comparisonPlans, 'first_comparison_php_bytes' => $reporter->firstComparisonBytes,
        'noops'            => $plan->noOpItemCount(), 'executables' => $plan->executableItemCount(),
    ], JSON_THROW_ON_ERROR));
    exit;
}

requireProfileInvariant($mode === 'profile', 'Unknown profile mode.');
$workspace = '/tmp/mixed-profile-' . bin2hex(random_bytes(8));
$nativeBin = getenv('BENCHMARK_NATIVE_BIN') ?: $workspace . '/bin';
$filesystem->mkdir([$workspace . '/media', $nativeBin], 0o700);
$filesystem->dumpFile($workspace . '/synthetic.marker', "photo-renamer-synthetic-profile\n");
$seedId   = '00000000-0000-4000-8000-000000000001';
$seedDate = new DateTimeImmutable('2024-01-01T00:00:01+01:00');

try {
    $templates = [];

    foreach (['jpg', 'heic'] as $extension) {
        $image = new Imagick();
        $image->newImage(64, 64, new ImagickPixel('blue'));
        $image->setImageFormat($extension === 'jpg' ? 'jpeg' : 'heic');
        $image->profileImage('exif', syntheticExif($seedId, $seedDate->format('Y:m:d H:i:s')));
        $templates[$extension] = $image->getImageBlob();
        $image->clear();
    }
    $video    = $workspace . '/seed.mov';
    $generate = new Process(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:r=1', '-t', '1', '-threads', '1', '-c:v', 'mpeg4', '-movflags', 'use_metadata_tags', '-metadata', 'creation_time=' . $seedDate->format('Y-m-d\TH:i:sP'), '-metadata', 'com.apple.quicktime.creationdate=' . $seedDate->format('Y-m-d\TH:i:sP'), '-metadata', 'com.apple.quicktime.content.identifier=' . $seedId, $video]);
    $generate->setTimeout(20);
    $generate->mustRun();
    $templates['mov'] = $filesystem->readFile($video);

    foreach ($templates as $extension => $bytes) {
        requireProfileInvariant(substr_count($bytes, $seedId) === 1, 'Synthetic content identifier is not uniquely replaceable.');
    }

    for ($index = 0; $index < intdiv($count, 2); ++$index) {
        $date  = $seedDate->modify('+' . $index . ' seconds');
        $id    = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
        $still = ($index % 2) === 0 ? 'jpg' : 'heic';

        foreach ([$still, 'mov'] as $extension) {
            $oldDate = $seedDate->format($extension === 'mov' ? 'Y-m-d\TH:i:sP' : 'Y:m:d H:i:s');
            $newDate = $date->format($extension === 'mov' ? 'Y-m-d\TH:i:sP' : 'Y:m:d H:i:s');
            requireProfileInvariant(substr_count($templates[$extension], $oldDate) >= 1, 'Capture date is not replaceable.');
            $filesystem->dumpFile(sprintf('%s/media/item-%06d.%s', $workspace, $index, $extension), str_replace([$seedId, $oldDate], [$id, $newDate], $templates[$extension]));
        }

        if (($index > 0) && (($index % 5000) === 0)) {
            printf("generated_files=%d\n", $index * 2);
        }
    }
    printf("generated_files=%d jpeg=%d heic=%d mov=%d duration_bucket_videos=%d potential_bucket_pairs=%d payload_bytes=%d\n", $count, intdiv($count, 4), intdiv($count, 4), intdiv($count, 2), intdiv($count, 2), intdiv(intdiv($count, 2) * (intdiv($count, 2) - 1), 2), intdiv($count, 4) * (strlen($templates['jpg']) + strlen($templates['heic']) + 2 * strlen($templates['mov'])));

    foreach (['ffprobe', 'ffmpeg'] as $binary) {
        $filesystem->dumpFile($nativeBin . '/' . $binary, "#!/bin/sh\necho " . $binary . " >> \"\$BENCHMARK_NATIVE_COUNTER\"\nexec /usr/bin/" . $binary . " \"\$@\"\n");
        $filesystem->chmod($nativeBin . '/' . $binary, 0o700);
    }
    // Prove instrumentation can observe a real native launch before reporting
    // zero launches in the distinct-ID pipeline. The control is not a phase.
    $controlCounter = $workspace . '/counter-control';
    $filesystem->dumpFile($controlCounter, '');
    $control = new Process([$nativeBin . '/ffprobe', '-v', 'error', $video], env: ['BENCHMARK_NATIVE_COUNTER' => $controlCounter]);
    $control->setTimeout(20);
    $control->mustRun();
    requireProfileInvariant($filesystem->readFile($controlCounter) === "ffprobe\n", 'Native subprocess instrumentation did not observe its control.');

    foreach (['cold', 'warm', 'mutate', 'idempotency'] as $phase) {
        $counter = $workspace . '/native-counter';
        $filesystem->dumpFile($counter, '');
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=3072M', __FILE__, 'worker', (string) $count, $workspace, $phase], env: ['BENCHMARK_PROJECT_ROOT' => $project, 'BENCHMARK_NATIVE_COUNTER' => $counter, 'PATH' => $nativeBin . ':' . (getenv('PATH') ?: '/usr/bin:/bin')]);
        $process->setTimeout(1200);
        $process->start();
        $peakTmp = 0;

        while ($process->isRunning()) {
            $process->checkTimeout();
            $peakTmp = max($peakTmp, (int) (disk_total_space('/tmp') - disk_free_space('/tmp')));
            usleep(100000);
        }

        if (!$process->isSuccessful()) {
            printf("worker_failed phase=%s exit=%d sampled_tmp_peak_bytes=%d native_subprocesses=%d\n", $phase, $process->getExitCode(), $peakTmp, substr_count($filesystem->readFile($counter), "\n"));

            throw new RuntimeException('Profile worker failed: ' . $process->getErrorOutput() . $process->getOutput());
        }
        $metrics                           = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $metrics['native_subprocesses']    = substr_count($filesystem->readFile($counter), "\n");
        $metrics['sampled_tmp_peak_bytes'] = $peakTmp;
        $metrics['tmp_sample_interval_ms'] = 100;
        printf("%s\n", json_encode($metrics, JSON_THROW_ON_ERROR));
    }
} finally {
    $filesystem->remove($workspace);
}
