<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use Imagick;
use ImagickException;
use ImagickPixel;
use InvalidArgumentException;
use LogicException;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function file_exists;
use function fileperms;
use function getenv;
use function glob;
use function is_writable;
use function posix_geteuid;

require dirname(__DIR__) . '/.build/vendor/autoload.php';

/**
 * Reports a security/compatibility invariant explicitly without relying on asserts.
 *
 * @param bool   $condition Required outcome
 * @param string $message   Non-sensitive failure diagnostic
 *
 * @return void
 */
function requireRuntimeInvariant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mode       = $argv[1] ?? 'verify';
$directory  = $argv[2] ?? '/media';
$filesystem = new Filesystem();

if ($mode === 'generate') {
    $filesystem->mkdir($directory);

    foreach (['jpg', 'jpeg', 'heic', 'heif'] as $extension) {
        $image = new Imagick();
        $image->newImage(16, 16, new ImagickPixel('red'));
        $image->setImageFormat(($extension === 'heic') || ($extension === 'heif') ? 'heic' : 'jpeg');
        $image->writeImage($directory . '/2024-01-01_12-00-00-000.' . $extension);
        $image->clear();
    }

    $wideImage = new Imagick();
    $wideImage->newImage(64, 2, new ImagickPixel('blue'));
    $wideImage->setImageFormat('jpeg');
    $wideImage->writeImage($directory . '/wide.jpg');
    $wideImage->clear();

    $tallImage = new Imagick();
    $tallImage->newImage(2, 64, new ImagickPixel('blue'));
    $tallImage->setImageFormat('jpeg');
    $tallImage->writeImage($directory . '/tall.jpg');
    $tallImage->clear();
    $filesystem->copy($directory . '/2024-01-01_12-00-00-000.jpg', $directory . '/filename with spaces.jpg');

    $sequence = new Imagick();

    foreach (['red', 'blue'] as $color) {
        $frame = new Imagick();
        $frame->newImage(16, 16, new ImagickPixel($color));
        $frame->setImageFormat('heic');
        $sequence->addImage($frame);
        $frame->clear();
    }

    $sequence->writeImages($directory . '/sequence.heic', true);
    $sequence->clear();

    foreach (['avi', 'mov', 'mp4', 'm4v'] as $extension) {
        new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=green:s=16x16:d=0.2',
            '-frames:v', '2', '-c:v', 'mpeg4',
            $directory . '/2024-01-01_12-00-00-000.' . $extension,
        ])->mustRun();
    }

    echo "Tiny synthetic JPEG/HEIC/HEIF/AVI/MOV/MP4/M4V and oversized-relative-to-test-policy fixtures generated.\n";
    exit(0);
}

requireRuntimeInvariant(getenv('COMPOSER_AUTH') === false, 'Runtime inherited Composer credentials.');
requireRuntimeInvariant(getenv('SSH_AUTH_SOCK') === false, 'Runtime inherited SSH-agent configuration.');
requireRuntimeInvariant(!file_exists('/ssh-agent'), 'Runtime mounted an SSH agent.');
requireRuntimeInvariant(!file_exists('/app/.git') && !file_exists('/app/.env'), 'Runtime exposes repository credentials.');
$loader = new ImagickImageLoader(new MediaTypeClassifier());
requireRuntimeInvariant(glob('/sys/class/net/*/ifindex') === ['/sys/class/net/lo/ifindex'], 'Runtime has an external network interface.');

try {
    $filesystem->dumpFile('/app/runtime-write-probe', 'synthetic');

    throw new LogicException('Runtime code is writable.');
} catch (IOExceptionInterface) {
    // The immutable artifact must reject even an otherwise valid local write.
}

if ($mode === 'verify') {
    requireRuntimeInvariant(posix_geteuid() > 0, 'Runtime media processing is privileged.');
    requireRuntimeInvariant(is_writable('/cache'), 'Runtime cache volume is unavailable to its UID.');
    $storage          = new PrivateCacheStorage($filesystem);
    $privateDirectory = $storage->directory('/tmp/synthetic-cache');
    $probe            = $privateDirectory . '/runtime-isolation-check.json';
    $storage->write($probe, '{"synthetic":true}');
    requireRuntimeInvariant((fileperms($privateDirectory) & 0777) === 0700, 'Runtime private cache directory is not owner-only.');
    requireRuntimeInvariant((fileperms($probe) & 0777) === 0600, 'Runtime cache file is not owner-only.');
    $storage->remove($probe);

    try {
        $filesystem->dumpFile($directory . '/media-write-probe', 'synthetic');

        throw new LogicException('Read-only analysis media are writable.');
    } catch (IOExceptionInterface) {
        // The compatibility check deliberately mounts only synthetic media read-only.
    }

    foreach ([
        Imagick::RESOURCETYPE_MEMORY     => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_MAP        => 512 * 1024 * 1024,
        Imagick::RESOURCETYPE_TIME       => 30,
        Imagick::RESOURCETYPE_DISK       => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_WIDTH      => 32768,
        Imagick::RESOURCETYPE_HEIGHT     => 32768,
        Imagick::RESOURCETYPE_LISTLENGTH => 64,
    ] as $resource => $expected) {
        requireRuntimeInvariant(Imagick::getResourceLimit($resource) === (float) $expected, 'Unexpected effective runtime resource limit: ' . $resource);
    }

    foreach (['jpg', 'jpeg', 'heic', 'heif', 'avi', 'mov', 'mp4', 'm4v'] as $extension) {
        $image = $loader->loadNormalized(new SplFileInfo($directory . '/2024-01-01_12-00-00-000.' . $extension));
        requireRuntimeInvariant($image instanceof Imagick, 'Supported media failed to normalize: ' . $extension);
        $image?->clear();
    }

    $validSequence = new Imagick();
    $validSequence->readImage($directory . '/sequence.heic');
    requireRuntimeInvariant($validSequence->getNumberImages() === 2, 'Sequence negative control is not a valid two-image input.');
    $validSequence->clear();

    try {
        new Imagick()->readImage('xc:red');

        throw new LogicException('Unneeded pseudo-coder was permitted.');
    } catch (ImagickException) {
        // Coder rejection must happen locally; no external endpoint is contacted.
    }

    echo "Effective memory/map/time/disk/dimension/list limits, supported formats and coder restrictions verified.\n";
} elseif ($mode === 'low-limits') {
    requireRuntimeInvariant(Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH) === 32.0, 'Small width policy is inactive.');
    requireRuntimeInvariant(Imagick::getResourceLimit(Imagick::RESOURCETYPE_HEIGHT) === 32.0, 'Small height policy is inactive.');
    requireRuntimeInvariant(Imagick::getResourceLimit(Imagick::RESOURCETYPE_LISTLENGTH) === 2.0, 'Small sequence policy is inactive.');
    $valid = $loader->loadNormalized(new SplFileInfo($directory . '/2024-01-01_12-00-00-000.jpg'));
    requireRuntimeInvariant($valid instanceof Imagick, 'Small policy rejected a valid image.');
    $valid?->clear();
    requireRuntimeInvariant($loader->loadNormalized(new SplFileInfo($directory . '/wide.jpg')) === null, 'Oversized test width was accepted.');
    requireRuntimeInvariant($loader->loadNormalized(new SplFileInfo($directory . '/tall.jpg')) === null, 'Oversized test height was accepted.');
    requireRuntimeInvariant($loader->loadNormalized(new SplFileInfo($directory . '/sequence.heic')) === null, 'Oversized test sequence was accepted.');
    echo "Small-policy oversized image/sequence inputs rejected without large allocations.\n";
} elseif ($mode === 'low-disk') {
    requireRuntimeInvariant(Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY) === 0.0, 'Small memory policy is inactive.');
    requireRuntimeInvariant(Imagick::getResourceLimit(Imagick::RESOURCETYPE_MAP) === 0.0, 'Small map policy is inactive.');
    requireRuntimeInvariant(Imagick::getResourceLimit(Imagick::RESOURCETYPE_DISK) === 1024.0, 'Small disk policy is inactive.');
    requireRuntimeInvariant($loader->loadNormalized(new SplFileInfo($directory . '/2024-01-01_12-00-00-000.jpg')) === null, 'Pixel cache exceeded the tiny disk budget.');
    echo "1-KiB pixel-cache disk limit rejected a tiny image without filling storage.\n";
} else {
    throw new InvalidArgumentException('Unknown synthetic runtime smoke mode.');
}
