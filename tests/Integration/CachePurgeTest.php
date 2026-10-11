<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function posix_geteuid;

use const PHP_BINARY;

/**
 * Runs the actual purge script against a synthetic project/cache tree to verify
 * migration without deleting the repository's real DI or development caches.
 */
#[CoversNothing]
final class CachePurgeTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Runs actual maintenance against two cached synthetic sources, removing
     * only the missing path from each snapshot. Existing media, journals, DI
     * metadata and unrelated data remain intact; an active mutation lock blocks
     * maintenance before any selected snapshot can be changed.
     *
     * @return void
     */
    #[Test]
    public function pruningPreservesMediaAndHistoryAndRespectsMutationLock(): void
    {
        $workspace  = $this->createTempWorkspace('cache-prune-script-');
        $filesystem = new Filesystem();
        $base       = $workspace . '/cache';
        $storage    = new PrivateCacheStorage($filesystem);
        $private    = $storage->directory($base);
        $script     = $workspace . '/scripts/prune-cache.php';
        $filesystem->copy(dirname(__DIR__, 2) . '/scripts/prune-cache.php', $script);
        $filesystem->symlink(dirname(__DIR__, 2) . '/.build/vendor/autoload.php', $workspace . '/.build/vendor/autoload.php');

        $retained = $workspace . '/retained.jpg';
        $removed  = $workspace . '/removed.jpg';
        $filesystem->dumpFile($retained, 'retained synthetic media');
        $filesystem->dumpFile($removed, 'removed synthetic media');

        $metadata = new MetadataCache($private . '/metadata-cache.json', $storage);
        $signals  = new PerceptualSignalCache($private . '/perceptual-signal-cache.json', $storage);

        foreach ([$retained, $removed] as $source) {
            $metadata->set(new SplFileInfo($source), null);
            $signals->set(new SplFileInfo($source), ['dhash' => null, 'whash' => null, 'hf' => null, 'hist' => null]);
        }

        $metadata->flush();
        $signals->flush();
        $filesystem->remove($removed);
        $filesystem->dumpFile($private . '/mutation-synthetic.jsonl', 'retained history');
        $filesystem->dumpFile($workspace . '/.build/cache/DependencyContainer.php.meta', 'retained DI metadata');

        $process = new Process([PHP_BINARY, $script], $workspace, ['CACHE_DIR' => $base]);
        $process->setTimeout(10.0);

        try {
            $lock     = new BatchRunLock($filesystem, $base)->acquire();
            $snapshot = $filesystem->readFile($private . '/metadata-cache.json');

            try {
                $process->run();
                self::assertNotSame(0, $process->getExitCode());
                self::assertStringContainsString('Another mutating renamer', $process->getOutput() . $process->getErrorOutput());
                self::assertSame($snapshot, $filesystem->readFile($private . '/metadata-cache.json'));
            } finally {
                $lock->release();
            }

            $process->mustRun();
            self::assertStringContainsString('Pruned 1 metadata and 1 perceptual entries', $process->getOutput());
            self::assertSame('retained synthetic media', $filesystem->readFile($retained));
            self::assertSame('retained history', $filesystem->readFile($private . '/mutation-synthetic.jsonl'));
            self::assertSame('retained DI metadata', $filesystem->readFile($workspace . '/.build/cache/DependencyContainer.php.meta'));
            $fresh = new MetadataCache($private . '/metadata-cache.json', $storage);
            self::assertNotNull($fresh->get(new SplFileInfo($retained)));
            self::assertNull($fresh->get(new SplFileInfo($removed)));
            $process->mustRun();
            self::assertStringContainsString('Pruned 0 metadata and 0 perceptual entries', $process->getOutput());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * The current UID's private caches, old flat caches and separate DI cache are
     * deleted. Unrelated JSON, other user children and directories are retained.
     *
     * @param string|null $cacheOverride Custom directory name, empty override or unset environment
     */
    #[Test]
    #[DataProvider('cacheConfigurations')]
    public function purgesCurrentAndLegacyCachesWithoutDeletingOtherUserData(?string $cacheOverride): void
    {
        $workspace      = $this->createTempWorkspace();
        $filesystem     = new Filesystem();
        $base           = $workspace . '/' . (($cacheOverride !== null) && ($cacheOverride !== '') ? $cacheOverride : '.build/cache');
        $ownDirectory   = $base . '/private-' . posix_geteuid();
        $otherDirectory = $base . '/private-' . (posix_geteuid() + 1);
        $script         = $this->preparePurgeScript($workspace, $filesystem);
        $paths          = [
            $ownDirectory . '/metadata-cache.json',
            $ownDirectory . '/perceptual-signal-cache.json',
            $base . '/metadata-cache.json',
            $base . '/perceptual-signal-cache.json',
            $workspace . '/.build/cache/DependencyContainer.php',
            $workspace . '/.build/cache/DependencyContainer.php.meta',
            $workspace . '/.build/cache/DependencyContainer.php.meta.json',
        ];

        foreach ($paths as $pathname) {
            $filesystem->dumpFile($pathname, 'synthetic cache');
        }

        $filesystem->dumpFile($otherDirectory . '/metadata-cache.json', 'other cache');
        $filesystem->dumpFile($base . '/unrelated.json', 'retained');

        try {
            $environmentValue = match ($cacheOverride) {
                null    => false,
                ''      => '',
                default => $base,
            };
            $process = new Process([PHP_BINARY, $script], $workspace, ['CACHE_DIR' => $environmentValue]);
            $process->mustRun();

            foreach ($paths as $pathname) {
                self::assertFileDoesNotExist($pathname);
            }

            self::assertDirectoryExists($ownDirectory);
            self::assertSame('other cache', $filesystem->readFile($otherDirectory . '/metadata-cache.json'));
            self::assertSame('retained', $filesystem->readFile($base . '/unrelated.json'));
            $process->mustRun();
            self::assertSame(0, $process->getExitCode(), 'Repeated purge is safe.');
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string|null}> Custom, empty and absent CACHE_DIR values
     */
    public static function cacheConfigurations(): iterable
    {
        yield 'custom base with spaces' => ['shared base'];
        yield 'empty override uses default' => [''];
        yield 'unset override uses default' => [null];
    }

    /**
     * A linked private child must be rejected before purge follows it into an
     * unrelated owned directory or changes that directory's permissions.
     */
    #[Test]
    public function refusesLinkedPrivateDirectoryWithoutRemovingDestination(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $base       = $workspace . '/cache';
        $script     = $this->preparePurgeScript($workspace, $filesystem);
        $filesystem->mkdir($base);
        $filesystem->dumpFile($workspace . '/unrelated/metadata-cache.json', 'retained');
        $filesystem->symlink($workspace . '/unrelated', $base . '/private-' . posix_geteuid());

        try {
            $process = new Process([PHP_BINARY, $script], $workspace, ['CACHE_DIR' => $base]);
            $process->run();
            self::assertNotSame(0, $process->getExitCode());
            self::assertStringContainsString('symbolic link', $process->getOutput() . $process->getErrorOutput());
            self::assertSame('retained', $filesystem->readFile($workspace . '/unrelated/metadata-cache.json'));
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Runtime purge removes media JSON while preserving the precompiled immutable
     * DI artifact. An invalid option fails before touching any selected file.
     *
     * @param string $option Requested script mode
     * @param bool   $purged Whether the media cache must be removed
     */
    #[Test]
    #[DataProvider('runtimePurgeModes')]
    public function preservesCompiledContainerDuringRuntimePurge(string $option, bool $purged): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $script     = $this->preparePurgeScript($workspace, $filesystem);
        $base       = $workspace . '/cache';
        $mediaCache = $base . '/private-' . posix_geteuid() . '/metadata-cache.json';
        $container  = $workspace . '/.build/cache/DependencyContainer.php';
        $filesystem->dumpFile($mediaCache, 'synthetic metadata');
        $filesystem->dumpFile($container, 'immutable container');
        $filesystem->dumpFile($container . '.meta', 'immutable resources');
        $filesystem->dumpFile($container . '.meta.json', 'immutable resource diagnostics');

        try {
            $process = new Process([PHP_BINARY, $script, $option], $workspace, ['CACHE_DIR' => $base]);
            $process->run();
            self::assertSame('immutable container', $filesystem->readFile($container));
            self::assertSame('immutable resources', $filesystem->readFile($container . '.meta'));
            self::assertSame('immutable resource diagnostics', $filesystem->readFile($container . '.meta.json'));

            if ($purged) {
                self::assertSame(0, $process->getExitCode());
                self::assertFileDoesNotExist($mediaCache);
            } else {
                self::assertNotSame(0, $process->getExitCode());
                self::assertSame('synthetic metadata', $filesystem->readFile($mediaCache));
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string, bool}> Runtime mode and a misspelled destructive option
     */
    public static function runtimePurgeModes(): iterable
    {
        yield 'media-only preserves executable DI' => ['--media-only', true];
        yield 'typo must not purge anything' => ['--media-onyl', false];
    }

    /**
     * Copies the entry point into an isolated project with only its autoloader
     * referring back to the repository, so all paths selected for purge are fake.
     *
     * @param string     $workspace  Temporary project root
     * @param Filesystem $filesystem Fixture filesystem boundary
     *
     * @return string Isolated executable PHP script
     */
    private function preparePurgeScript(string $workspace, Filesystem $filesystem): string
    {
        $root   = dirname(__DIR__, 2);
        $script = $workspace . '/scripts/clear-cache.php';
        $filesystem->copy($root . '/scripts/clear-cache.php', $script);
        $filesystem->symlink($root . '/.build/vendor/autoload.php', $workspace . '/.build/vendor/autoload.php');

        return $script;
    }
}
