<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use DateTimeZone;
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\MetadataCacheEntry;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;

use function getenv;
use function is_string;
use function putenv;
use function realpath;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies shared command metadata-cache configuration.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversTrait(ConfiguresMetadataProvider::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(MetadataCacheEntry::class)]
#[UsesClass(PerceptualSignalCache::class)]
#[UsesClass(ExifMetadataProvider::class)]
final class ConfiguresMetadataProviderTest extends TestCase
{
    /**
     * Ensures default command caches are rooted in the project build directory,
     * not below `src/`, when no CACHE_DIR override is configured.
     */
    #[Test]
    public function defaultCacheFilesUseProjectBuildCacheDirectory(): void
    {
        $previousCacheDir = getenv('CACHE_DIR');
        putenv('CACHE_DIR');

        try {
            [$metadataCache, $signalCache] = $this->createCaches();
            $projectRoot                   = realpath(__DIR__ . '/../../..');

            self::assertIsString($projectRoot);

            $expectedPrefix = $projectRoot
                . DIRECTORY_SEPARATOR . '.build'
                . DIRECTORY_SEPARATOR . 'cache'
                . DIRECTORY_SEPARATOR;
            $sourcePrefix = $projectRoot
                . DIRECTORY_SEPARATOR . 'src'
                . DIRECTORY_SEPARATOR;

            foreach ([$metadataCache, $signalCache] as $cache) {
                $cacheFile = $this->cacheFile($cache);

                self::assertStringStartsWith($expectedPrefix, $cacheFile);
                self::assertFalse(
                    str_starts_with($cacheFile, $sourcePrefix),
                    'Runtime cache files must not be created below src/.',
                );
            }
        } finally {
            if (is_string($previousCacheDir)) {
                putenv('CACHE_DIR=' . $previousCacheDir);
            } else {
                putenv('CACHE_DIR');
            }
        }
    }

    /**
     * Verifies timezone resolution priority and validation.
     */
    #[Test]
    public function resolveTimezoneUsesCliOptionBeforeEnvironmentAndRejectsInvalidValues(): void
    {
        $previousTimezone = getenv('TIMEZONE');
        putenv('TIMEZONE=UTC');

        try {
            $harness = new class {
                use ConfiguresMetadataProvider;

                public function timezone(InputInterface $input): ?DateTimeZone
                {
                    return $this->resolveTimezone($input);
                }

                protected function getCommandFilesystem(): Filesystem
                {
                    return new Filesystem();
                }
            };

            self::assertSame(
                'Europe/Berlin',
                $harness->timezone($this->input(['--timezone' => 'Europe/Berlin']))?->getName(),
            );
            self::assertSame('UTC', $harness->timezone($this->input([]))?->getName());
            self::assertNull($harness->timezone($this->input(['--timezone' => 'Invalid/Zone'])));
        } finally {
            $this->restoreEnv('TIMEZONE', $previousTimezone);
        }
    }

    /**
     * Verifies numeric threshold resolution from CLI options, environment, and defaults.
     */
    #[Test]
    public function resolveThresholdsUseCliEnvironmentAndDefaults(): void
    {
        $previousDateDrift = getenv('MAX_DATE_DRIFT');
        $previousMerge     = getenv('MERGE_THRESHOLD');
        putenv('MAX_DATE_DRIFT=12');
        putenv('MERGE_THRESHOLD=0.08');

        try {
            $harness = new class {
                use ConfiguresMetadataProvider;

                public function maxDateDrift(InputInterface $input): int
                {
                    return $this->resolveMaxDateDrift($input);
                }

                public function mergeThreshold(InputInterface $input): float
                {
                    return $this->resolveMergeThreshold($input);
                }

                protected function getCommandFilesystem(): Filesystem
                {
                    return new Filesystem();
                }
            };

            self::assertSame(3, $harness->maxDateDrift($this->input(['--max-date-drift' => '3'])));
            self::assertSame(12, $harness->maxDateDrift($this->input([])));
            self::assertSame(0.04, $harness->mergeThreshold($this->input(['--merge-threshold' => '0.04'])));
            self::assertSame(0.08, $harness->mergeThreshold($this->input([])));

            putenv('MAX_DATE_DRIFT');
            putenv('MERGE_THRESHOLD');

            self::assertSame(7, $harness->maxDateDrift($this->input([])));
            self::assertSame(0.06, $harness->mergeThreshold($this->input([])));
        } finally {
            $this->restoreEnv('MAX_DATE_DRIFT', $previousDateDrift);
            $this->restoreEnv('MERGE_THRESHOLD', $previousMerge);
        }
    }

    /**
     * @return array{MetadataCache, PerceptualSignalCache}
     */
    private function createCaches(): array
    {
        $harness = new class {
            use ConfiguresMetadataProvider;

            public function metadataCache(): MetadataCache
            {
                return $this->configureProviderCache(new ExifMetadataProvider(new StubMetadataExtractor()));
            }

            public function signalCache(): PerceptualSignalCache
            {
                return $this->createPerceptualSignalCache();
            }

            protected function getCommandFilesystem(): Filesystem
            {
                return new Filesystem();
            }
        };

        return [$harness->metadataCache(), $harness->signalCache()];
    }

    private function cacheFile(object $cache): string
    {
        $property = new ReflectionProperty($cache, 'cacheFile');
        $value    = $property->getValue($cache);

        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, string> $options
     */
    private function input(array $options): ArrayInput
    {
        return new ArrayInput(
            $options,
            new InputDefinition([
                new InputOption('timezone', null, InputOption::VALUE_REQUIRED),
                new InputOption('max-date-drift', null, InputOption::VALUE_REQUIRED),
                new InputOption('merge-threshold', null, InputOption::VALUE_REQUIRED),
            ]),
        );
    }

    private function restoreEnv(string $name, bool|string $previousValue): void
    {
        if (is_string($previousValue)) {
            putenv($name . '=' . $previousValue);

            return;
        }

        putenv($name);
    }
}
