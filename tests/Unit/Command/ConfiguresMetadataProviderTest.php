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
use InvalidArgumentException;
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\MetadataCacheEntry;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function getenv;
use function is_string;
use function posix_geteuid;
use function putenv;
use function realpath;
use function str_replace;
use function str_starts_with;
use function strtoupper;

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
#[UsesClass(PrivateCacheStorage::class)]
final class ConfiguresMetadataProviderTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Supplies an unrepresentable floating-point signal to the real cache.
     * JSON persistence must produce a warning through the command concern,
     * while clean no-op flushes remain silent and create no cache file.
     *
     * @return void
     */
    #[Test]
    public function cacheSerializationFailureIsDiagnosedWithoutThrowing(): void
    {
        $workspace = $this->createTempWorkspace('cache-serialization-');

        try {
            $filesystem = new Filesystem();
            $source     = $workspace . '/synthetic.jpg';
            $filesystem->dumpFile($source, 'synthetic bytes');
            $cache = new PerceptualSignalCache($workspace . '/signals.json', new PrivateCacheStorage($filesystem));
            self::assertSame('', $this->flushThroughConcern($cache));
            self::assertFileDoesNotExist($workspace . '/signals.json');
            $cache->set(new SplFileInfo($source), ['dhash' => null, 'whash' => null, 'hf' => INF, 'hist' => null]);
            $output = $this->flushThroughConcern($cache);
            self::assertStringContainsString('Cache persistence failed (JsonException)', $output);
            self::assertStringContainsString('Completed media operations remain counted', $output);
            self::assertFileDoesNotExist($workspace . '/signals.json');
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Replaces a selected cache leaf with a symlink after initialization.
     * Optional-cache error handling must not suppress this storage-boundary
     * violation or write into the unrelated target behind the link.
     *
     * @return void
     */
    #[Test]
    public function cachePrivacyFailureRemainsExplicit(): void
    {
        $workspace = $this->createTempWorkspace('cache-privacy-');

        try {
            $filesystem = new Filesystem();
            $source     = $workspace . '/synthetic.jpg';
            $filesystem->dumpFile($source, 'synthetic bytes');
            $filesystem->dumpFile($workspace . '/unrelated.json', 'retained');
            $cache = new MetadataCache($workspace . '/metadata.json', new PrivateCacheStorage($filesystem));
            $cache->set(new SplFileInfo($source), null);
            $filesystem->symlink($workspace . '/unrelated.json', $workspace . '/metadata.json');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/symbolic link/');

            try {
                $this->flushThroughConcern($cache);
            } finally {
                self::assertSame('retained', $filesystem->readFile($workspace . '/unrelated.json'));
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Invokes the production concern's persistence boundary with captured CLI
     * output. The anonymous harness exposes only the protected method and
     * supplies the existing filesystem contract explicitly.
     *
     * @param MetadataCache|PerceptualSignalCache $cache Real cache whose failure behavior is observed
     *
     * @return string Safe warning output, or an empty string for a successful flush
     */
    private function flushThroughConcern(MetadataCache|PerceptualSignalCache $cache): string
    {
        $harness = new class {
            use ConfiguresMetadataProvider;

            /**
             * Exposes the protected cache boundary without duplicating its logic.
             *
             * @param MetadataCache|PerceptualSignalCache $cache Real cache instance
             * @param SymfonyStyle                        $io    Captured test output
             *
             * @return void
             */
            public function persist(MetadataCache|PerceptualSignalCache $cache, SymfonyStyle $io): void
            {
                $this->flushCache($cache, $io);
            }

            /**
             * Supplies the concern's required Symfony filesystem boundary.
             *
             * @return Filesystem Test-local filesystem collaborator
             */
            protected function getCommandFilesystem(): Filesystem
            {
                return new Filesystem();
            }
        };
        $output = new BufferedOutput();
        $harness->persist($cache, new SymfonyStyle(new ArrayInput([]), $output));

        return $output->fetch();
    }

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
            $projectRoot                   = realpath(dirname(__DIR__, 3));
            $projectRoot                   = $projectRoot !== false ? $projectRoot : dirname(__DIR__, 3);

            $expectedPrefix = $projectRoot
                . DIRECTORY_SEPARATOR . '.build'
                . DIRECTORY_SEPARATOR . 'cache'
                . DIRECTORY_SEPARATOR . 'private-' . posix_geteuid()
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
            $this->restoreEnv('CACHE_DIR', $previousCacheDir);
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
            $this->expectException(InvalidArgumentException::class);
            $harness->timezone($this->input(['--timezone' => 'Invalid/Zone']));
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
            self::assertSame(0, $harness->maxDateDrift($this->input(['--max-date-drift' => '0'])));
            self::assertSame(0.0, $harness->mergeThreshold($this->input(['--merge-threshold' => '0'])));
            self::assertSame(1.0, $harness->mergeThreshold($this->input(['--merge-threshold' => '1'])));

            putenv('MAX_DATE_DRIFT=invalid');
            putenv('MERGE_THRESHOLD=invalid');
            self::assertSame(3, $harness->maxDateDrift($this->input(['--max-date-drift' => '3'])));
            self::assertSame(0.04, $harness->mergeThreshold($this->input(['--merge-threshold' => '0.04'])));

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
     * Explicit malformed or out-of-range values must never become an absent
     * timezone, disabled drift check or silently coerced merge policy. Test
     * CLI and environment independently, with a valid environment fallback
     * when the malformed value is supplied explicitly through the CLI.
     *
     * @param string $option  CLI option whose actual concern resolver is exercised
     * @param string $value   Invalid explicit value
     * @param bool   $fromCli Whether CLI precedence supplies the invalid value
     *
     * @return void
     */
    #[Test]
    #[DataProvider('invalidExplicitSettings')]
    public function invalidExplicitSettingsAreRejected(string $option, string $value, bool $fromCli): void
    {
        $environment = strtoupper(str_replace('-', '_', $option));
        $previous    = getenv($environment);
        $fallback    = match ($option) {
            'timezone'       => 'UTC',
            'max-date-drift' => '7',
            default          => '0.06',
        };
        putenv($environment . '=' . ($fromCli ? $fallback : $value));

        try {
            $harness = new class {
                use ConfiguresMetadataProvider;

                /**
                 * Exposes the three protected resolvers without reproducing
                 * validation or adding a production configuration abstraction.
                 *
                 * @param string         $option Known option selected by the provider
                 * @param InputInterface $input  Bound test input with CLI precedence
                 *
                 * @return DateTimeZone|int|float|null The selected runtime setting
                 */
                public function resolve(string $option, InputInterface $input): DateTimeZone|int|float|null
                {
                    return match ($option) {
                        'timezone'       => $this->resolveTimezone($input),
                        'max-date-drift' => $this->resolveMaxDateDrift($input),
                        default          => $this->resolveMergeThreshold($input),
                    };
                }

                /**
                 * Supplies the concern's required filesystem boundary; none of
                 * these configuration resolution calls performs filesystem I/O.
                 *
                 * @return Filesystem Test-local filesystem collaborator
                 */
                protected function getCommandFilesystem(): Filesystem
                {
                    return new Filesystem();
                }
            };
            $this->expectException(InvalidArgumentException::class);
            $harness->resolve($option, $this->input($fromCli ? ['--' . $option => $value] : []));
        } finally {
            $this->restoreEnv($environment, $previous);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}> Malformed values and range boundaries from both input sources
     */
    public static function invalidExplicitSettings(): iterable
    {
        $cases = [
            ['timezone', 'Invalid/Zone'],
            ['max-date-drift', 'abc'],
            ['max-date-drift', '-1'],
            ['max-date-drift', '1.5'],
            ['max-date-drift', '999999999999999999999999999999'],
            ['merge-threshold', 'abc'],
            ['merge-threshold', '-0.01'],
            ['merge-threshold', '1.01'],
            ['merge-threshold', '1e9999'],
            ['merge-threshold', 'NaN'],
        ];

        foreach ($cases as [$option, $value]) {
            foreach ([false, true] as $fromCli) {
                yield $option . ' ' . $value . ($fromCli ? ' CLI' : ' environment') => [$option, $value, $fromCli];
            }
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
