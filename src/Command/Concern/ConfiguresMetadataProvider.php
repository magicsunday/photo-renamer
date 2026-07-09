<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command\Concern;

use DateTimeZone;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Service\FormatPriorityResolver;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use Phar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Filesystem\Filesystem;

use function getenv;
use function in_array;
use function is_string;
use function realpath;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

/**
 * Trait providing shared configuration logic for commands using EXIF metadata.
 *
 * This trait centralizes the setup of the `ExifMetadataProvider`, including:
 * - Timezone resolution for video files (CLI option > ENV > default).
 * - Persistent metadata cache management (ENV > project default > PHAR fallback).
 * - Configuration of perceptual signal caches for duplicate detection.
 * - Resolution of various thresholds (date drift, merge similarity).
 *
 * It ensures a consistent configuration across different rename and verification
 * commands.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
trait ConfiguresMetadataProvider
{
    /**
     * Provides the command-facing filesystem boundary used by metadata-related
     * cache helpers in this concern.
     *
     * Commands using this trait already own the runtime command boundary, so
     * the trait must not instantiate its own Filesystem silently.
     */
    abstract protected function getCommandFilesystem(): Filesystem;

    /**
     * Configures the default timezone on the EXIF metadata provider.
     *
     * This is essential for correctly converting UTC timestamps from QuickTime
     * video files (MOV, MP4, M4V) when they don't contain explicit timezone
     * metadata, ensuring they match the local time of accompanying photos.
     *
     * @param ExifMetadataProvider $provider The provider to configure.
     * @param InputInterface       $input    The console input used to resolve
     *                                       the --timezone option.
     */
    protected function configureProviderTimezone(ExifMetadataProvider $provider, InputInterface $input): void
    {
        $timezone = $this->resolveTimezone($input);

        if ($timezone instanceof DateTimeZone) {
            $provider->setDefaultTimezone($timezone);
        }
    }

    /**
     * Resolves the configured timezone from the --timezone option or the
     * TIMEZONE environment variable.
     *
     * @param InputInterface $input The console input to check for CLI options.
     *
     * @return DateTimeZone|null The resolved timezone, or null if no valid
     *                           timezone was configured.
     */
    protected function resolveTimezone(InputInterface $input): ?DateTimeZone
    {
        $timezone = $input->getOption('timezone');

        if (!is_string($timezone)) {
            $timezone = FileHelper::env('TIMEZONE');
        }

        if ((!is_string($timezone)) || (!in_array($timezone, DateTimeZone::listIdentifiers(), true))) {
            return null;
        }

        return new DateTimeZone($timezone);
    }

    /**
     * Configures and initializes the persistent metadata cache.
     *
     * This speeds up subsequent runs by storing extracted EXIF/QuickTime
     * metadata in a JSON file, avoiding expensive re-extraction with exiftool.
     *
     * @param ExifMetadataProvider $provider The provider to which the cache
     *                                       should be attached.
     *
     * @return MetadataCache The initialized cache instance.
     */
    protected function configureProviderCache(ExifMetadataProvider $provider): MetadataCache
    {
        $cache = new MetadataCache($this->resolveCacheDir() . '/metadata-cache.json', $this->getCommandFilesystem());

        $provider->setCache($cache);

        return $cache;
    }

    /**
     * Creates and returns a persistent cache for perceptual similarity signals.
     *
     * This cache stores computed dHash, wHash, and HF-energy values to avoid
     * expensive re-computation during perceptual duplicate detection.
     *
     * @return PerceptualSignalCache The initialized signal cache.
     */
    protected function createPerceptualSignalCache(): PerceptualSignalCache
    {
        return new PerceptualSignalCache($this->resolveCacheDir() . '/perceptual-signal-cache.json', $this->getCommandFilesystem());
    }

    /**
     * Resolves the absolute path to the cache directory.
     *
     * The method determines the directory based on environment variables,
     * whether the application is running as a PHAR archive (which is read-only),
     * or the default project structure.
     *
     * @return string The resolved absolute path to the cache directory.
     */
    private function resolveCacheDir(): string
    {
        $envDir = FileHelper::env('CACHE_DIR');

        if ($envDir !== null) {
            return $envDir;
        }

        // Inside a PHAR, __DIR__ points into the archive — use home directory instead
        if (Phar::running() !== '') {
            $home = getenv('HOME');

            return is_string($home) && ($home !== '')
                ? $home . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'renamer'
                : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'renamer-cache';
        }

        $projectRoot = realpath(__DIR__ . '/../../..');

        return (is_string($projectRoot) ? $projectRoot : __DIR__ . '/../../..')
            . DIRECTORY_SEPARATOR . '.build'
            . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * Resolves and validates the source directory path from the input argument.
     *
     * @param InputInterface $input The console input carrying the argument.
     *
     * @return string|null Absolute source directory path, or null if the
     *                     directory is invalid or does not exist.
     */
    protected function resolveSourceDirectory(InputInterface $input): ?string
    {
        /** @var string|null $directory */
        $directory = $input->getArgument('source-directory');

        return PathHelper::resolveDirectory($directory);
    }

    /**
     * Resolves the maximum allowed date drift threshold in days.
     *
     * This threshold determines how much the capture date of a file may differ
     * from the date indicated by its filename before a warning is issued.
     *
     * @param InputInterface $input   The console input carrying the option.
     * @param int            $default The default threshold if nothing else is set.
     *
     * @return int The resolved threshold in days.
     */
    protected function resolveMaxDateDrift(InputInterface $input, int $default = 7): int
    {
        $driftOption = $input->getOption('max-date-drift');

        if (is_string($driftOption)) {
            return (int) $driftOption;
        }

        $envDrift = FileHelper::env('MAX_DATE_DRIFT');

        return $envDrift !== null ? (int) $envDrift : $default;
    }

    /**
     * Resolves the merge threshold for perceptual duplicate detection.
     *
     * The threshold represents the maximum allowed Root Mean Square Error (RMSE)
     * between two images to consider them visually identical.
     *
     * @param InputInterface $input   The console input carrying the option.
     * @param float          $default The default threshold if nothing else is set.
     *
     * @return float The resolved threshold as a fraction between 0.0 and 1.0.
     */
    protected function resolveMergeThreshold(InputInterface $input, float $default = 0.06): float
    {
        $option = $input->getOption('merge-threshold');

        if (is_string($option)) {
            return (float) $option;
        }

        $envValue = FileHelper::env('MERGE_THRESHOLD');

        return $envValue !== null ? (float) $envValue : $default;
    }

    /**
     * Resolves the list of preferred file formats for canonical selection.
     *
     * This priority list determines which file extension (e.g., HEIC over JPEG)
     * should be favored when selecting the primary 'canonical' file of a group.
     *
     * @return list<string> The list of file extensions in descending priority.
     */
    protected function resolveFormatPriority(): array
    {
        return FormatPriorityResolver::resolve();
    }
}
