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
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function glob;
use function sprintf;
use function str_contains;

use const PHP_BINARY;

/**
 * Starts the real CLI with a shutdown observer to measure its effective PHP
 * memory budget, including defaults, overrides and rejected unlimited values.
 */
#[CoversNothing]
final class RuntimeMemoryLimitTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A missing override selects a finite default, and a valid positive budget
     * remains effective after the entry point instead of being reset to -1.
     *
     * @param string|null $configured Requested environment override or an unset value
     * @param string      $expected   Effective memory_limit expected after CLI startup
     */
    #[Test]
    #[DataProvider('finiteBudgets')]
    public function cliUsesFiniteDefaultAndHonorsPositiveOverrides(?string $configured, string $expected): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $observer   = $workspace . '/observer.php';
        $filesystem->dumpFile($observer, <<<'PHP'
            <?php
            register_shutdown_function(static function (): void {
                echo "\nobserved-memory-limit=", ini_get('memory_limit'), "\n";
            });
            PHP);

        try {
            $process = new Process([
                PHP_BINARY,
                '-d',
                'auto_prepend_file=' . $observer,
                dirname(__DIR__, 2) . '/src/Renamer.php',
                '--version',
            ], dirname(__DIR__, 2), ['PHP_MEMORY_LIMIT' => $configured ?? false]);
            $process->mustRun();
            self::assertStringContainsString('observed-memory-limit=' . $expected, $process->getOutput());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * A real collection exceeding a 32-MiB analysis budget must exit normally
     * before renaming any media. The mutating run still takes its lock, captures
     * fresh identities and records failure; dry-run must stop just as visibly.
     * The previous disk snapshot is preserved rather than serialized on abort,
     * and every source's bytes and pathname remain unchanged.
     *
     * @param bool $dryRun Whether the actual CLI is invoked as a preview
     *
     * @return void
     */
    #[Test]
    #[DataProvider('analysisModes')]
    public function plannedMemoryBoundaryStopsBeforeMutation(bool $dryRun): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $media      = $workspace . '/media';
        $state      = $workspace . '/state';
        $cacheFile  = new PrivateCacheStorage($filesystem)->directory($state) . '/metadata-cache.json';
        $sentinel   = '{}';
        $filesystem->dumpFile($cacheFile, $sentinel);
        $sources = [];

        try {
            for ($index = 0; $index < 6000; ++$index) {
                $source = $media . '/' . sprintf('synthetic-%06d.jpg', $index);
                $filesystem->dumpFile($source, 'synthetic source bytes');
                $sources[] = $source;
            }

            $arguments = [PHP_BINARY, dirname(__DIR__, 2) . '/src/Renamer.php', 'rename:exif', $media, '--no-ansi'];

            if ($dryRun) {
                $arguments[] = '--dry-run';
            }

            $process = new Process($arguments, dirname(__DIR__, 2), ['PHP_MEMORY_LIMIT' => '32M', 'CACHE_DIR' => $state]);
            $process->setTimeout(60);
            $process->setInput($dryRun ? '' : "yes\n");
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString('Analysis incomplete during ', $output);
            self::assertStringContainsString('No media mutation started', $output);
            self::assertFalse(str_contains($output, 'Allowed memory size'));
            self::assertFalse(str_contains($output, 'done'));
            self::assertSame($sentinel, $filesystem->readFile($cacheFile));
            $remaining = glob($media . '/*.jpg');
            self::assertIsArray($remaining);
            self::assertCount(6000, $remaining);

            foreach ($sources as $source) {
                self::assertSame('synthetic source bytes', $filesystem->readFile($source));
            }

            $journals = glob(new PrivateCacheStorage($filesystem)->directory($state) . '/mutation-*.jsonl');
            self::assertIsArray($journals);

            if ($dryRun) {
                self::assertSame([], $journals);
            } else {
                self::assertCount(1, $journals);
                $journal = $filesystem->readFile($journals[0]);
                self::assertStringContainsString('"event":"run_finished","exit_code":1', $journal);
                self::assertFalse(str_contains($journal, '"event":"intent"'));
                $lock = new BatchRunLock($filesystem, $state)->acquire();
                self::assertTrue($lock->isAcquired());
                $lock->release();
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{bool}> Mutation and preview command scopes
     */
    public static function analysisModes(): iterable
    {
        yield 'mutation with source identity and journal' => [false];
        yield 'preview' => [true];
    }

    /**
     * @return iterable<string, array{string|null, string}> Default and supported finite overrides
     */
    public static function finiteBudgets(): iterable
    {
        yield 'unset defaults to 1 GiB' => [null, '1024M'];
        yield 'empty defaults to 1 GiB' => ['', '1024M'];
        yield 'explicit megabytes' => ['64M', '64M'];
        yield 'explicit gigabytes' => ['2G', '2G'];
    }

    /**
     * Unlimited, zero and malformed overrides must fail visibly before startup
     * so a typo cannot silently remove the memory boundary.
     *
     * @param string $configured Invalid memory-budget environment value
     */
    #[Test]
    #[DataProvider('invalidBudgets')]
    public function rejectsUnlimitedAndInvalidBudgetsBeforeRunningCommand(string $configured): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/src/Renamer.php',
            '--version',
        ], dirname(__DIR__, 2), ['PHP_MEMORY_LIMIT' => $configured]);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('PHP_MEMORY_LIMIT', $process->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string}> Values that must never weaken the finite bound
     */
    public static function invalidBudgets(): iterable
    {
        yield 'unlimited' => ['-1'];
        yield 'zero' => ['0'];
        yield 'not a quantity' => ['unlimited'];
        yield 'trailing garbage' => ['64MB'];
    }
}
