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
