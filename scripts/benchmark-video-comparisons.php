<?php

/**
 * Measures bounded synthetic cross-group planning with the real stream matcher.
 * Cold/warm results isolate its in-process cache; original media is never used.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Benchmark;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoDuplicateReconciler;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use RuntimeException;
use SplFileInfo;

use function bin2hex;
use function count;
use function dirname;
use function filter_var;
use function hrtime;
use function intdiv;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function printf;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

use const FILTER_VALIDATE_INT;

require dirname(__DIR__) . '/.build/vendor/autoload.php';

// Bounded synthetic payload: one tiny generated video copied to at most 1,000
// paths. The warm pass reuses the actual matcher's in-process fingerprint cache.
// This is a planning/cache benchmark, not a representative collection throughput test.
$count = filter_var($argv[1] ?? '1000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 1000]]);
if ($count === false) {
    throw new InvalidArgumentException('Supply a candidate count between 2 and 1000.');
}

$filesystem = new Filesystem();
$workspace = sys_get_temp_dir() . '/comparison-benchmark-' . bin2hex(random_bytes(8));
$filesystem->mkdir($workspace, 0o700);
$matcher = new VideoStreamFingerprintMatcher();
$reconciler = new CrossGroupVideoDuplicateReconciler(
    new MediaCompatibilityPolicy(new MediaTypeClassifier()),
    $matcher,
    new NullProgressReporter(),
    new ComparisonWorkLimit(100000),
);

try {
    $fixture = $workspace . '/fixture.mp4';
    $process = new Process(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=16x16:r=1', '-t', '1', '-threads', '1', '-c:v', 'mpeg4', $fixture]);
    $process->setTimeout(20);
    $process->mustRun();

    for ($index = 0; $index < $count; ++$index) {
        $filesystem->copy($fixture, sprintf('%s/video-%04d.mp4', $workspace, $index));
    }

    foreach (['cold', 'warm'] as $cacheState) {
        $groups = new AssetGroupCollection();
        for ($index = 0; $index < $count; ++$index) {
            $group = new AssetGroup(sprintf('capture-%04d', $index));
            $group->addItem(new AssetItem(
                new SplFileInfo(sprintf('%s/video-%04d.mp4', $workspace, $index)),
                metadata: new TemporalMetadata(new DateTimeImmutable('2024-01-01'), null, false, false, null, null, null, null, null, null, 1.0),
            ));
            $groups->set($group->groupKey, $group);
        }

        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $start = hrtime(true);
        $reconciler->reconcile($groups, new PipelineContext($workspace));
        $seconds = (hrtime(true) - $start) / 1e9;
        if ((count($groups) !== 1) || ($groups->get('capture-0000')?->itemCount() !== $count)) {
            throw new RuntimeException('Synthetic exact duplicates did not converge to one complete group.');
        }

        printf("%s candidates=%d duration_bucket_pairs=%d seconds=%.4f peak_growth_bytes=%d php_peak_bytes=%d groups=%d\n", $cacheState, $count, intdiv($count * ($count - 1), 2), $seconds, memory_get_peak_usage() - $baseline, memory_get_peak_usage(), count($groups));
    }
} finally {
    $filesystem->remove($workspace);
}
