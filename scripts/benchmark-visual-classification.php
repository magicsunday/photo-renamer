<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Benchmark;

use Imagick;
use ImagickDraw;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Model\MergeDecision;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\ContentClassifier;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashMath;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

use function abs;
use function array_column;
use function array_filter;
use function array_key_last;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function floor;
use function fwrite;
use function getrusage;
use function hash;
use function hrtime;
use function in_array;
use function ini_get;
use function intdiv;
use function is_finite;
use function json_decode;
use function json_encode;
use function max;
use function memory_get_peak_usage;
use function min;
use function phpversion;
use function preg_match;
use function round;
use function sort;
use function sprintf;
use function sqrt;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const PHP_VERSION;
use const STDERR;

require '/app/.build/vendor/autoload.php';

/**
 * Draws deterministic texture/edge families with a real central detail for edit controls.
 *
 * @param int $family       Fixed family index; determines seed, texture and source resolution
 * @param int $objectOffset Horizontal movement of the central detail for independent burst rendering
 *
 * @return Imagick Distinct sRGB pixels before lossy encoding
 */
function familyImage(int $family, int $objectOffset = 0): Imagick
{
    $width = ($family % 2) === 0 ? 512 : 1536;
    $image = new Imagick();
    $image->newImage($width, intdiv($width * 3, 4), '#467aaf');
    $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
    $random = new Randomizer(new Mt19937(20261014 + $family));
    $draw   = new ImagickDraw();
    $draw->setStrokeWidth(0);

    for ($row = 0; $row < $image->getImageHeight(); ++$row) {
        $fraction = $row / max(1, $image->getImageHeight() - 1);
        $draw->setFillColor(sprintf('#%02x%02x%02x', (int) round(70 + 148 * $fraction), (int) round(122 + 72 * $fraction), (int) round(175 - 39 * $fraction)));
        $draw->rectangle(0, $row, $width - 1, $row);
    }
    $count = match ($family % 3) {
        0 => 8, 1 => 320, default => 45,
    };

    for ($i = 0; $i < $count; ++$i) {
        $x    = $random->getInt(0, $width - 20);
        $y    = $random->getInt(0, $image->getImageHeight() - 20);
        $size = $random->getInt(4, max(5, intdiv($width, 12)));
        $draw->setFillColor(sprintf('#%02x%02x%02x', $random->getInt(30, 220), $random->getInt(30, 220), $random->getInt(30, 220)));
        $draw->rectangle($x, $y, min($width - 1, $x + $size), min($image->getImageHeight() - 1, $y + $size));
    }
    $x = intdiv($width, 2) + $objectOffset;
    $y = intdiv($image->getImageHeight(), 2);
    $draw->setFillColor('#303030');

    for ($bar = 0; $bar < 4; ++$bar) {
        $draw->rectangle($x + 3 + $bar * 6, $y + 3, $x + 5 + $bar * 6, $y + 26);
    }
    $image->drawImage($draw);
    $draw->clear();

    return $image;
}

/**
 * Encodes a shallow image clone without changing the generation source.
 *
 * @param Imagick $image   Synthetic pixels to encode
 * @param string  $path    Owned temporary JPEG or HEIC pathname
 * @param int     $quality Explicit native encoder quality
 *
 * @return void
 */
function encode(Imagick $image, string $path, int $quality): void
{
    $copy = clone $image;

    try {
        $copy->setImageFormat(str_ends_with($path, '.heic') ? 'heic' : 'jpeg');
        $copy->setImageCompressionQuality($quality);
        $copy->setImageDepth(8);
        $copy->writeImage($path);
    } finally {
        $copy->clear();
    }
}

/**
 * Declares semantic labels and separate family splits before any score is measured.
 *
 * @param Filesystem $fs   Filesystem for owned synthetic fixtures and pinned CC0 profiles
 * @param string     $root Private temporary generation directory
 *
 * @return list<array<string, bool|int|float|string|null>> Pair labels with synthetic paths
 */
function corpus(Filesystem $fs, string $root): array
{
    $labels = [];
    $srgb   = $fs->readFile(__DIR__ . '/fixtures/icc/sRGB-v4.icc');
    $p3     = $fs->readFile(__DIR__ . '/fixtures/icc/DisplayP3-v4.icc');

    for ($family = 0; $family < 12; ++$family) {
        $name = sprintf('family-%02d', $family);
        $dir  = $root . '/' . $name;
        $fs->mkdir($dir);
        $base   = familyImage($family);
        $source = $dir . '/source.jpg';
        encode($base, $source, 95);
        $heicControlPath = $dir . '/unedited-control.heic';
        encode($base, $heicControlPath, 95);
        $heicControl = new Imagick($heicControlPath);
        $jpegControl = new Imagick($source);
        $variants    = ['identity', 'jpeg-85', 'jpeg-60', 'heic-70', 'heic-40', 'reencode', 'scale-half', 'orientation', 'srgb-profile', 'p3-profile', 'gray', 'hue', 'crop', 'rotation', 'blur-detail', 'remove-detail', 'insert-detail', 'near-burst'];

        foreach ([1, 4, 16, 64] as $side) {
            $variants[] = 'patch-' . $side . '-jpg';
            $variants[] = 'patch-' . $side . '-heic';
        }

        foreach ($variants as $variant) {
            $image     = clone $base;
            $extension = str_contains($variant, 'heic') ? 'heic' : 'jpg';
            $target    = $dir . '/' . $variant . '.' . $extension;
            $positive  = in_array($variant, array_slice($variants, 0, 10), true);
            $side      = null;
            $quality   = 95;

            try {
                if ($variant === 'identity') {
                    $fs->copy($source, $target);
                } else {
                    if (preg_match('/^(jpeg|heic)-(\d+)$/', $variant, $match) === 1) {
                        $quality = (int) $match[2];
                    } elseif ($variant === 'reencode') {
                        $image->clear();
                        $image->readImage($source);
                        $quality = 80;
                    } elseif ($variant === 'scale-half') {
                        $image->resizeImage(intdiv($image->getImageWidth(), 2), intdiv($image->getImageHeight(), 2), Imagick::FILTER_LANCZOS, 1.0);
                    } elseif ($variant === 'orientation') {
                        $image->rotateImage('white', -90);
                    } elseif ($variant === 'srgb-profile' || $variant === 'p3-profile') {
                        $image->profileImage('icc', $srgb);

                        if ($variant === 'p3-profile') {
                            $image->profileImage('icc', $p3);
                        }
                    } elseif ($variant === 'near-burst') {
                        $image->clear();
                        $image = familyImage($family, 4);
                    } elseif ($variant === 'gray') {
                        $image->modulateImage(100, 0, 100);
                    } elseif ($variant === 'hue') {
                        $image->modulateImage(100, 100, 110);
                    } elseif ($variant === 'crop') {
                        $image->cropImage((int) ($image->getImageWidth() * 0.9), (int) ($image->getImageHeight() * 0.9), 0, 0);
                        $image->setImagePage(0, 0, 0, 0);
                    } elseif ($variant === 'rotation') {
                        $image->rotateImage('white', 90);
                    } else {
                        $side = str_starts_with($variant, 'patch-') ? (int) explode('-', $variant)[1] : 32;
                        $x    = intdiv($image->getImageWidth(), 2);
                        $y    = intdiv($image->getImageHeight(), 2);

                        if ($variant === 'blur-detail') {
                            $patch = $image->getImageRegion($side, $side, $x, $y);
                            $patch->blurImage(0, 4);
                            $image->compositeImage($patch, Imagick::COMPOSITE_COPY, $x, $y);
                            $patch->clear();
                        } else {
                            $draw = new ImagickDraw();
                            $draw->setFillColor($variant === 'remove-detail' ? '#8c9ca8' : '#e03c24');

                            if ($variant === 'insert-detail') {
                                $x += 48;
                            }
                            $draw->rectangle($x, $y, $x + $side - 1, $y + $side - 1);
                            $image->drawImage($draw);
                            $draw->clear();
                        }
                    }
                    encode($image, $target, $quality);

                    if ($variant === 'orientation') {
                        $process = new Process(['exiftool', '-overwrite_original', '-Orientation#=6', $target]);
                        $process->setTimeout(10);
                        $process->mustRun();
                    }
                }
                $editSurvives = true;

                if (!$positive) {
                    $decoded      = new Imagick($target);
                    $control      = $extension === 'heic' ? $heicControl : $jpegControl;
                    $editSurvives = $decoded->getImageSignature() !== $control->getImageSignature();
                    $decoded->clear();
                }
                $roundTripRmse = null;

                if ($variant === 'p3-profile') {
                    $decoded = new Imagick($target);
                    $decoded->profileImage('icc', $srgb);
                    $reference     = new Imagick($source);
                    $roundTripRmse = $reference->getImageDistortion($decoded, Imagick::METRIC_ROOTMEANSQUAREDERROR);
                    $reference->clear();
                    $decoded->clear();

                    if ($roundTripRmse > 0.02) {
                        throw new RuntimeException('Profile conversion fixture no longer preserves its displayed content');
                    }
                }
                $labels[] = ['encodedEditSurvives' => $editSurvives, 'profileRoundTripRmse' => $roundTripRmse, 'id' => $name . '/' . $variant, 'family' => $name, 'split' => $family < 6 ? 'calibration' : 'holdout', 'positive' => $positive, 'variant' => $variant, 'source' => $source, 'target' => $target, 'codec' => $extension, 'width' => $base->getImageWidth(), 'editSidePixels' => $side];
            } finally {
                $image->clear();
            }
        }
        $base->clear();
        $heicControl->clear();
        $jpegControl->clear();
    }
    $r42      = '/evaluation/regression42/';
    $r59      = '/evaluation/regression59/';
    $labels[] = ['id' => 'regression-42/format-backup', 'family' => 'regression-42', 'split' => 'regression', 'positive' => true, 'source' => $r42 . 'photo.heic', 'target' => $r42 . 'photo.jpg'];
    $files    = ['2009-07-25_11-27-50-100.jpg', '2009-07-25_11-27-50-100-002.jpg', '2009-07-25_11-27-50-100-003.jpg'];

    foreach ([[0, 1], [0, 2], [1, 2]] as [$a, $b]) {
        $labels[] = ['id' => 'regression-59/pair-' . $a . '-' . $b, 'family' => 'regression-59', 'split' => 'regression', 'positive' => false, 'source' => $r59 . $files[$a], 'target' => $r59 . $files[$b]];
    }

    return $labels;
}

/**
 * Uses the installed native distance API with explicit Gaussian window parameters.
 *
 * @param Imagick $left   Normalized grayscale reference, with comparison artifacts
 * @param Imagick $right  Grayscale comparison image with equal dimensions
 * @param int     $metric Installed SSIM or DSSIM metric constant
 *
 * @return float Native normalized distance; zero is measured equality, failure throws
 */
function distance(Imagick $left, Imagick $right, int $metric): float
{
    foreach (['compare:ssim-radius' => '5', 'compare:ssim-sigma' => '1.5', 'compare:ssim-k1' => '0.01', 'compare:ssim-k2' => '0.03'] as $key => $value) {
        $left->setImageArtifact($key, $value);
    }
    $value = $left->getImageDistortion($right, $metric);

    if (!is_finite($value)) {
        throw new RuntimeException('Native metric returned non-finite evidence');
    }

    return $value;
}

/**
 * Measures native identity/change/error behavior and SSIM/DSSIM API equivalence.
 *
 * @return array<string, array{identityDistance: float, onePixelDistance: float, emptyRejected: bool}> Preflight evidence
 */
function preflight(): array
{
    $left = new Imagick();
    $left->newImage(32, 32, 'gray');
    $right = clone $left;
    $right->setImagePixelColor(16, 16, 'white');
    $result = [];

    foreach (['ssim' => Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR, 'dssim' => Imagick::METRIC_STRUCTURAL_DISSIMILARITY_ERROR] as $name => $metric) {
        $identity = distance($left, $left, $metric);
        $changed  = distance($left, $right, $metric);
        $failed   = false;

        try {
            distance($left, new Imagick(), $metric);
        } catch (Throwable) {
            $failed = true;
        }

        if ($identity !== 0.0 || $changed <= 0 || !$failed) {
            throw new RuntimeException('Unexpected native equality/failure semantics');
        }
        $result[$name] = ['identityDistance' => $identity, 'onePixelDistance' => $changed, 'emptyRejected' => $failed];
    }

    if (abs($result['ssim']['onePixelDistance'] - $result['dssim']['onePixelDistance']) > 1e-12) {
        throw new RuntimeException('SSIM and DSSIM API distance equivalence changed; review interpretation');
    }
    $left->clear();
    $right->clear();

    return $result;
}

/**
 * Measures non-overlapping regional errors; SSIM output is never treated as a map.
 *
 * @param Imagick $left  Normalized full-decode source
 * @param Imagick $right Normalized full-decode counterpart
 * @param int     $size  Maximum long edge, without enlarging smaller sources
 *
 * @return array<string, float> 32-pixel tile RMSE max/q99 and 512-only SSIM/tile scores
 */
function localMetrics(Imagick $left, Imagick $right, int $size): array
{
    $started = hrtime(true);
    $a       = clone $left;
    $b       = clone $right;

    try {
        if (max($a->getImageWidth(), $a->getImageHeight()) > $size) {
            $a->resizeImage($size, $size, Imagick::FILTER_TRIANGLE, 1.0, true);
        }
        $width  = $a->getImageWidth();
        $height = $a->getImageHeight();
        $b->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1.0);
        $a->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $b->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $pixelsA       = $a->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        $pixelsB       = $b->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        $preprocessMs  = (hrtime(true) - $started) / 1e6;
        $regionalStart = hrtime(true);
        $tiles         = [];

        for ($y = 0; $y < $height; $y += 32) {
            for ($x = 0; $x < $width; $x += 32) {
                $sum = 0;
                $n   = 0;

                for ($dy = $y; $dy < min($y + 32, $height); ++$dy) {
                    for ($dx = $x; $dx < min($x + 32, $width); ++$dx) {
                        $index = $dy * $width + $dx;
                        $sum += ($pixelsA[$index] - $pixelsB[$index]) ** 2;
                        ++$n;
                    }
                }
                $tiles[] = sqrt($sum / $n) / 255;
            }
        }
        sort($tiles);
        $result = ['preprocessMs' => $preprocessMs, 'regionalMs' => (hrtime(true) - $regionalStart) / 1e6, 'tileRmseMax' => max($tiles), 'tileRmseQ99' => $tiles[(int) floor((count($tiles) - 1) * 0.99)]];

        if ($size === 512) {
            $ssimStart              = hrtime(true);
            $result['ssimDistance'] = distance($a, $b, Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR);
            $result['ssimMs']       = (hrtime(true) - $ssimStart) / 1e6;
            $localStart             = hrtime(true);
            $worst                  = 0.0;

            for ($y = 0; $y < $height; $y += 128) {
                for ($x = 0; $x < $width; $x += 128) {
                    $tileA = $a->getImageRegion(min(128, $width - $x), min(128, $height - $y), $x, $y);
                    $tileB = $b->getImageRegion(min(128, $width - $x), min(128, $height - $y), $x, $y);
                    $worst = max($worst, distance($tileA, $tileB, Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR));
                    $tileA->clear();
                    $tileB->clear();
                }
            }
            $result['localSsimMax'] = $worst;
            $result['localSsimMs']  = (hrtime(true) - $localStart) / 1e6;
        }

        return $result;
    } finally {
        $a->clear();
        $b->clear();
    }
}

/**
 * Runs one fresh family worker using actual production classification and isolated signals.
 * Cold is an empty snapshot pass; warm reloads its persisted signals in fresh workers.
 * Neither phase clears kernel caches. Native Stage B and extra measurements still decode.
 *
 * @param Filesystem $fs     Owned temporary labels and cache I/O
 * @param string     $root   Synthetic fixture workspace
 * @param string     $phase  Report label for cold or warm snapshot state
 * @param string     $family Independent family to measure within native/process deadlines
 *
 * @return array{phase: string, rows: list<array<string, bool|int|float|string|null>>, seconds: float, phpPeakBytes: int, linuxPeakRssBytes: int, cpuSeconds: float} Actual worker measurements
 */
function worker(Filesystem $fs, string $root, string $phase, string $family): array
{
    $rows     = array_values(array_filter(json_decode($fs->readFile($root . '/labels.json'), true, flags: JSON_THROW_ON_ERROR), static fn (array $row): bool => $row['family'] === $family));
    $media    = new MediaTypeClassifier();
    $reporter = new NullProgressReporter();
    $loader   = new ImagickImageLoader($media, $reporter);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 128 * 1024 * 1024);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 128 * 1024 * 1024);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 30);
    $storage    = new PrivateCacheStorage($fs);
    $signals    = new PerceptualSignalCache($storage->directory($root . '/cache') . '/signals.json', $storage);
    $calculator = new PerceptualHashCalculator($loader, new PerceptualHashMath());
    $calculator->setSignalCache($signals);
    $analyzer   = new LocalDifferenceAnalyzer();
    $classifier = new ContentClassifier(new SafeHashCalculator(), $reporter, $media, $calculator, $analyzer, $loader, new ComparisonWorkLimit(100000));
    $started    = hrtime(true);

    foreach ($rows as &$row) {
        $source                = new SplFileInfo($row['source']);
        $target                = new SplFileInfo($row['target']);
        $start                 = hrtime(true);
        $decisions             = [];
        $groups                = $classifier->classify([$source, $target], $source, null, onDecision: static function (MergeDecision $decision) use (&$decisions): void { $decisions[] = $decision; });
        $row['baselineMs']     = (hrtime(true) - $start) / 1e6;
        $row['baselineAccept'] = count($groups->groups) === 1;
        $last                  = $decisions === [] ? null : $decisions[array_key_last($decisions)];
        $row['reason']         = $last?->reason->value;
        $row['baselineRmse']   = $last?->rmse;
        $row['baselineChroma'] = $last?->chromaDifference;
        $similarity            = $calculator->similarityScore($source, $target);
        $row['stageAAccept']   = $similarity->analysisAvailable && $similarity->isDuplicateLikely();
        $row['dhashDistance']  = $similarity->dhashDistance;
        $classifier->clearCache();
        $start = hrtime(true);
        $a     = $loader->loadNormalized($source);
        $b     = $loader->loadNormalized($target);

        if ($a === null || $b === null) {
            throw new RuntimeException('Synthetic normalized decode unavailable: ' . $row['id']);
        }

        try {
            $row['decodeMs'] = (hrtime(true) - $start) / 1e6;
            $diff            = $analyzer->analyzeRmse($a, $b);

            if (!$diff->success) {
                throw new RuntimeException('Synthetic Stage B measurement unavailable');
            }
            $row['rmse']   = $diff->rmse;
            $row['chroma'] = $diff->chromaDifference;

            foreach ([256, 512, 1024] as $size) {
                $start   = hrtime(true);
                $metrics = localMetrics($a, $b, $size);

                foreach ($metrics as $name => $value) {
                    $row[$name . $size] = $value;
                }
                $row['metricsMs' . $size] = (hrtime(true) - $start) / 1e6;
            }
        } finally {
            $a->clear();
            $b->clear();
        }
        unset($row['source'], $row['target']);
    }
    unset($row);
    $signals->flush();
    $usage = getrusage();

    return ['phase' => $phase, 'rows' => $rows, 'seconds' => (hrtime(true) - $started) / 1e9, 'phpPeakBytes' => memory_get_peak_usage(true), 'linuxPeakRssBytes' => $usage['ru_maxrss'] * 1024, 'cpuSeconds' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6];
}

/**
 * Freezes candidate vetoes from accepted calibration backups, then evaluates unseen families.
 * Negative labels never select thresholds. Each candidate preserves all baseline vetoes.
 *
 * @param list<array<string, bool|int|float|string|null>> $rows Cold observations containing labels and measured signals
 *
 * @return array{calibrationAcceptedBackups: int, thresholds: array<string, float>, decisions: array<string, array<string, array<string, int|list<string>>>>} Thresholds and independent decisions
 */
function summarize(array $rows): array
{
    $metrics          = ['ssimDistance512', 'localSsimMax512', 'tileRmseMax256', 'tileRmseQ99256', 'tileRmseMax512', 'tileRmseQ99512', 'tileRmseMax1024', 'tileRmseQ991024'];
    $thresholds       = [];
    $calibrationCount = 0;

    foreach ($rows as $row) {
        if ($row['split'] !== 'calibration' || !$row['positive'] || !$row['baselineAccept']) {
            continue;
        }
        ++$calibrationCount;

        foreach ($metrics as $metric) {
            $thresholds[$metric] = max($thresholds[$metric] ?? 0.0, $row[$metric]);
        }
    }

    if ($calibrationCount === 0) {
        throw new RuntimeException('No accepted calibration backups; no justified veto can be calibrated');
    }
    $methods = ['baseline' => []];

    foreach ($metrics as $metric) {
        $methods[$metric] = [$metric];
    }
    $methods['localSsimAndRegional512'] = ['localSsimMax512', 'tileRmseMax512'];
    $results                            = [];

    foreach ($methods as $name => $signals) {
        foreach (['calibration', 'holdout', 'regression'] as $split) {
            $stats = ['unobservableEditsExcluded' => 0, 'pairs' => 0, 'positives' => 0, 'negatives' => 0, 'falseMerges' => 0, 'missedBackups' => 0, 'additionalMissedBackups' => 0, 'errorIds' => []];

            foreach ($rows as $row) {
                if ($row['split'] !== $split) {
                    continue;
                }

                if (!$row['positive'] && !($row['encodedEditSurvives'] ?? true)) {
                    ++$stats['unobservableEditsExcluded'];

                    continue;
                }
                ++$stats['pairs'];
                ++$stats[$row['positive'] ? 'positives' : 'negatives'];
                $accept = $row['baselineAccept'];

                foreach ($signals as $metric) {
                    $accept = $accept && $row[$metric] <= $thresholds[$metric] + 1e-12;
                }

                if (!$row['positive'] && $accept) {
                    ++$stats['falseMerges'];
                    $stats['errorIds'][] = $row['id'];
                } elseif ($row['positive'] && !$accept) {
                    ++$stats['missedBackups'];
                    $stats['errorIds'][] = $row['id'];

                    if ($row['baselineAccept']) {
                        ++$stats['additionalMissedBackups'];
                    }
                }
            }
            $results[$name][$split] = $stats;
        }
    }

    return ['calibrationAcceptedBackups' => $calibrationCount, 'thresholds' => $thresholds, 'decisions' => $results];
}

$fs   = new Filesystem();
$root = '/tmp/visual-evaluation';
Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 128 * 1024 * 1024);
Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 128 * 1024 * 1024);
Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, 64 * 1024 * 1024);
Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 1800);

if (($argv[1] ?? '') === 'worker') {
    echo json_encode(worker($fs, $root, $argv[2], $argv[3]), JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
$native = preflight();
$fs->mkdir($root, 0o700);
$generationStart   = hrtime(true);
$labels            = corpus($fs, $root);
$generationSeconds = (hrtime(true) - $generationStart) / 1e9;
$fs->dumpFile($root . '/labels.json', json_encode($labels, JSON_THROW_ON_ERROR));
$reports  = [];
$families = array_values(array_unique(array_column($labels, 'family')));

foreach (['cold', 'warm'] as $phase) {
    $reports[$phase] = ['rows' => [], 'seconds' => 0.0, 'cpuSeconds' => 0.0, 'phpPeakBytes' => 0, 'linuxPeakRssBytes' => 0];

    foreach ($families as $family) {
        $child = new Process([PHP_BINARY, '-d', 'memory_limit=768M', __FILE__, 'worker', $phase, $family]);
        $child->setTimeout(90);
        $child->mustRun();
        $report                  = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $reports[$phase]['rows'] = array_merge($reports[$phase]['rows'], $report['rows']);

        foreach (['seconds', 'cpuSeconds'] as $metric) {
            $reports[$phase][$metric] += $report[$metric];
        }

        foreach (['phpPeakBytes', 'linuxPeakRssBytes'] as $metric) {
            $reports[$phase][$metric] = max($reports[$phase][$metric], $report[$metric]);
        }
        fwrite(STDERR, $phase . ' ' . $family . " complete\n");
    }
}
$limits = [];

foreach (['memory.max', 'memory.swap.max', 'cpu.max', 'pids.max'] as $name) {
    $path          = '/sys/fs/cgroup/' . $name;
    $limits[$name] = $fs->exists($path) ? trim($fs->readFile($path)) : null;
}
$limits['php']                     = ini_get('memory_limit');
$limits['nativeMemory']            = Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY);
$limits['nativeMap']               = Imagick::getResourceLimit(Imagick::RESOURCETYPE_MAP);
$limits['nativeDisk']              = Imagick::getResourceLimit(Imagick::RESOURCETYPE_DISK);
$limits['nativeWorkerTimeSeconds'] = 30;
$limits['nativeThreads']           = Imagick::getResourceLimit(Imagick::RESOURCETYPE_THREAD);
$comparisonStable                  = true;

foreach ($reports['cold']['rows'] as $index => $cold) {
    $warm = $reports['warm']['rows'][$index];

    foreach ($cold as $key => $value) {
        if (!str_contains($key, 'Ms') && $value !== $warm[$key]) {
            $comparisonStable = false;
        }
    }
}
$temporaryBytes = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $temporaryBytes += $file->getSize();
    }
}
$manifest   = json_decode($fs->readFile('/app/.build/build-inputs.json'), true, flags: JSON_THROW_ON_ERROR);
$provenance = ['benchmark_sha256' => hash('sha256', $fs->readFile(__FILE__)), 'revision' => $manifest['revision'], 'workspace_modified' => $manifest['workspace_modified'], 'source_sha256' => $manifest['source_sha256'], 'composer_lock_sha256' => $manifest['composer_lock_sha256']];
$memoryPeak = $fs->exists('/sys/fs/cgroup/memory.peak') ? trim($fs->readFile('/sys/fs/cgroup/memory.peak')) : null;
$summary    = summarize($reports['cold']['rows']);
echo json_encode(['schema' => 1, 'seed' => 20261014, 'native' => $native, 'imagick' => phpversion('imagick'), 'imagemagick' => Imagick::getVersion(), 'php' => PHP_VERSION, 'labelCount' => count($labels), 'coldWarmDecisionsAndSignalsIdentical' => $comparisonStable, 'summary' => $summary, 'runtimeProvenance' => $provenance, 'cgroupMemoryPeakBytes' => $memoryPeak, 'temporaryFileBytesAtEnd' => $temporaryBytes, 'generationSeconds' => $generationSeconds, 'effectiveLimits' => $limits, 'reports' => $reports], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";

if (!$comparisonStable) {
    throw new RuntimeException('Cold/warm decisions or measured signals differ');
}
