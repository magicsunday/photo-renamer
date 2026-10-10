<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
