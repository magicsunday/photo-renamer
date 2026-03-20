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
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Service\MetadataCache;
use Symfony\Component\Console\Input\InputInterface;

use function getenv;
use function is_string;

/**
 * Shared configuration logic for commands that use the ExifMetadataProvider:
 * timezone resolution (--timezone > TIMEZONE env > no conversion) and persistent
 * metadata cache setup (CACHE_DIR env > .build/cache default).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
trait ConfiguresMetadataProvider
{
    /**
     * Configures the default timezone on the EXIF metadata provider for converting
     * UTC timestamps from video files without explicit timezone metadata.
     *
     * Resolution order: --timezone CLI option > TIMEZONE env var > no conversion.
     */
    protected function configureProviderTimezone(ExifMetadataProvider $provider, InputInterface $input): void
    {
        $timezone = $input->getOption('timezone');

        if (!is_string($timezone)) {
            $envTimezone = getenv('TIMEZONE');
            $timezone    = is_string($envTimezone) && ($envTimezone !== '') ? $envTimezone : null;
        }

        if (is_string($timezone)) {
            $provider->setDefaultTimezone(new DateTimeZone($timezone));
        }
    }

    /**
     * Configures the persistent metadata cache on the EXIF metadata provider.
     * The cache directory is resolved from the CACHE_DIR env var, defaulting to
     * .build/cache relative to the project root.
     */
    protected function configureProviderCache(ExifMetadataProvider $provider): MetadataCache
    {
        $cacheDir = getenv('CACHE_DIR');

        if (!is_string($cacheDir) || ($cacheDir === '')) {
            $cacheDir = __DIR__ . '/../../../.build/cache';
        }

        $cache = new MetadataCache($cacheDir . '/metadata-cache.php');

        $provider->setCache($cache);

        return $cache;
    }

    /**
     * Resolves and validates the source directory path from the input argument.
     *
     * @return string|null Absolute source directory path, or null if invalid
     */
    protected function resolveSourceDirectory(InputInterface $input): ?string
    {
        /** @var string|null $directory */
        $directory = $input->getArgument('source-directory');

        return FileHelper::resolveDirectory($directory);
    }

    /**
     * Resolves the max date drift threshold from input option or env var.
     *
     * @param int $default Default drift in days when neither option nor env var is set
     */
    protected function resolveMaxDateDrift(InputInterface $input, int $default = 30): int
    {
        $driftOption = $input->getOption('max-date-drift');

        if (is_string($driftOption)) {
            return (int) $driftOption;
        }

        $envDrift = getenv('MAX_DATE_DRIFT');

        return is_string($envDrift) && $envDrift !== '' ? (int) $envDrift : $default;
    }
}
