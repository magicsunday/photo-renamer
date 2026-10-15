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
use InvalidArgumentException;
use JsonException;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Service\FormatPriorityResolver;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function filter_var;
use function in_array;
use function is_finite;
use function is_int;
use function is_numeric;
use function is_string;

use const FILTER_VALIDATE_INT;

/**
 * Trait providing shared configuration logic for commands using EXIF metadata.
 *
 * This trait centralizes the setup of the `ExifMetadataProvider`, including:
 * - Timezone resolution for video files (CLI option > ENV > default).
 * - Persistent metadata cache management (ENV > source/PHAR runtime default).
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
     *
     * @throws InvalidArgumentException When an explicit timezone is unsupported
     */
    protected function configureProviderTimezone(ExifMetadataProvider $provider, InputInterface $input): void
    {
        $timezone = $this->resolveTimezone($input);

        $provider->clearCache();
        $provider->setDefaultTimezone($timezone);
    }

    /**
     * Resolves the configured timezone from the --timezone option or the
     * TIMEZONE environment variable.
     *
     * @param InputInterface $input The console input to check for CLI options.
     *
     * @return DateTimeZone|null The resolved timezone, or null when no
     *                           timezone was configured.
     *
     * @throws InvalidArgumentException When the selected CLI/environment timezone is unsupported
     */
    protected function resolveTimezone(InputInterface $input): ?DateTimeZone
    {
        $timezone = $input->getOption('timezone');

        if (!is_string($timezone)) {
            $timezone = FileHelper::env('TIMEZONE');
        }

        if ($timezone === null) {
            return null;
        }

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('TIMEZONE / --timezone must name a supported timezone such as Europe/Berlin or UTC.');
        }

        return new DateTimeZone($timezone);
    }

    /**
     * Resolves terminal link settings once at the CLI boundary. Services receive
     * the immutable values explicitly and never re-read process environment.
     *
     * @return LinkConfig Current run's optional host path and protocol settings
     */
    protected function resolveLinkConfig(): LinkConfig
    {
        return new LinkConfig(FileHelper::env('FILE_LINK_ROOT'), FileHelper::env('FILE_LINK_BASE'), FileHelper::env('FILE_LINK_PROTOCOL'));
    }

    /**
     * Configures and initializes the persistent metadata cache.
     *
     * This speeds up subsequent runs by storing extracted EXIF/QuickTime
     * metadata in a JSON file, avoiding expensive re-extraction with exiftool.
     *
     * @param ExifMetadataProvider $provider       The provider to which the cache
     *                                             should be attached.
     * @param string|null          $cacheDirectory Already resolved run directory; null uses the command default
     *
     * @return MetadataCache The initialized cache instance.
     */
    protected function configureProviderCache(ExifMetadataProvider $provider, ?string $cacheDirectory = null): MetadataCache
    {
        $storage = new PrivateCacheStorage($this->getCommandFilesystem());
        $cache   = new MetadataCache($storage->directory($cacheDirectory ?? $this->resolveCacheDir()) . '/metadata-cache.json', $storage);

        $provider->setCache($cache);

        return $cache;
    }

    /**
     * Creates and returns a persistent cache for perceptual similarity signals.
     *
     * This cache stores computed dHash, wHash, and HF-energy values to avoid
     * expensive re-computation during perceptual duplicate detection.
     *
     * @param string|null $cacheDirectory Already resolved run directory; null uses the command default
     *
     * @return PerceptualSignalCache The initialized signal cache.
     */
    protected function createPerceptualSignalCache(?string $cacheDirectory = null): PerceptualSignalCache
    {
        $storage = new PrivateCacheStorage($this->getCommandFilesystem());

        return new PerceptualSignalCache($storage->directory($cacheDirectory ?? $this->resolveCacheDir()) . '/perceptual-signal-cache.json', $storage);
    }

    /**
     * Makes invalid persisted snapshots visible without exposing cached paths.
     * Cold-cache rejection is safe to continue; private-storage boundary errors
     * already throw during construction and are never suppressed here.
     *
     * @param MetadataCache|PerceptualSignalCache $cache Cache whose load outcome is reported
     * @param SymfonyStyle                        $io    Current command output
     *
     * @return void
     */
    protected function reportCacheDiagnostic(MetadataCache|PerceptualSignalCache $cache, SymfonyStyle $io): void
    {
        $diagnostic = $cache->getDiagnostic();

        if ($diagnostic !== null) {
            $io->warning($diagnostic);
        }
    }

    /**
     * Persists optional cache work without replacing a completed media outcome
     * with an undiagnosed I/O/serialization exception. Emit a safe warning and
     * retain the actual operation counts; ownership/link failures still throw.
     *
     * @param MetadataCache|PerceptualSignalCache $cache Cache to flush after analysis/mutation
     * @param SymfonyStyle                        $io    Current command output
     *
     * @return void
     */
    protected function flushCache(MetadataCache|PerceptualSignalCache $cache, SymfonyStyle $io): void
    {
        try {
            $cache->flush();
        } catch (IOException|JsonException $exception) {
            $io->warning('Cache persistence failed (' . $exception::class . '); cache updates may be lost. Completed media operations remain counted.');
        }
    }

    /**
     * Selects the shared host state base using the current command configuration.
     *
     * @return string Absolute runtime state base outside an immutable archive
     */
    protected function resolveCacheDir(): string
    {
        return FileHelper::resolveCacheDirectory(FileHelper::env('CACHE_DIR'));
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
     *
     * @throws InvalidArgumentException When the selected value is not a non-negative integer
     */
    protected function resolveMaxDateDrift(InputInterface $input, int $default = 7): int
    {
        $driftOption = $input->getOption('max-date-drift');

        $value = is_string($driftOption) ? $driftOption : FileHelper::env('MAX_DATE_DRIFT');

        if ($value === null) {
            return $default;
        }

        $drift = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (!is_int($drift)) {
            throw new InvalidArgumentException('MAX_DATE_DRIFT / --max-date-drift must be a non-negative integer; 0 explicitly disables the drift check.');
        }

        return $drift;
    }

    /**
     * Resolves the merge threshold for perceptual duplicate detection.
     *
     * This operator ceiling limits accepted perceptual merges; it does not prove
     * visual identity. The shared classifier still applies independent evidence
     * checks and may impose a stricter RMSE safety cap for the pair.
     *
     * @param InputInterface $input   The console input carrying the option.
     * @param float          $default The default threshold if nothing else is set.
     *
     * @return float The resolved threshold as a fraction between 0.0 and 1.0.
     *
     * @throws InvalidArgumentException When the selected value is non-numeric, non-finite or out of range
     */
    protected function resolveMergeThreshold(InputInterface $input, float $default = Constants::DEFAULT_MERGE_THRESHOLD): float
    {
        $option = $input->getOption('merge-threshold');

        $value = is_string($option) ? $option : FileHelper::env('MERGE_THRESHOLD');

        if ($value === null) {
            return $default;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException('MERGE_THRESHOLD / --merge-threshold must be a finite number from 0 to 1.');
        }

        $threshold = (float) $value;

        if (!is_finite($threshold) || ($threshold < 0.0) || ($threshold > 1.0)) {
            throw new InvalidArgumentException('MERGE_THRESHOLD / --merge-threshold must be a finite number from 0 to 1.');
        }

        return $threshold;
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
