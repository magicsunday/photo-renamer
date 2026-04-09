<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Guards the remaining lazy strategy construction in legacy commands.
 *
 * Wave 2 intentionally removed hidden strategy instantiation from the fixed
 * legacy commands. The only remaining `??= new` strategy construction is the
 * small allowlist of commands whose rename strategy depends on runtime CLI
 * options and therefore cannot be wired as a fixed service ahead of execution.
 *
 * @internal
 */
#[CoversNothing]
final class LazyCommandStrategyArchitectureTest extends TestCase
{
    /**
     * Verifies that lazy command-local strategy creation remains limited to the
     * explicitly allowlisted runtime-configured commands.
     */
    #[Test]
    public function lazyCommandStrategyInstantiationIsLimitedToRuntimeConfiguredCommands(): void
    {
        foreach ($this->commandFiles() as $pathname) {
            $contents = file_get_contents($pathname);

            self::assertNotFalse($contents);

            if ($this->isAllowedLazyStrategyCommand($pathname)) {
                continue;
            }

            self::assertSame(
                0,
                preg_match('/return\s+\$this->\w+\s+\?\?=\s+new\s+[A-Z_\\\\]/', $contents),
                sprintf('Unexpected lazy strategy construction in %s', $pathname),
            );
        }
    }

    /**
     * Collects command source files for the lazy-strategy scan.
     *
     * @return list<string> Absolute normalized command file paths
     */
    private function commandFiles(): array
    {
        $directory = __DIR__ . '/../../src/Command';
        $files     = [];

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            $pathname = str_replace('\\', DIRECTORY_SEPARATOR, $fileInfo->getPathname());

            if (str_ends_with($pathname, '.php')) {
                $files[] = $pathname;
            }
        }

        return $files;
    }

    /**
     * Returns whether the command is an explicit allowlisted runtime-strategy boundary.
     *
     * These commands build rename strategies from CLI-supplied patterns or
     * filename formats that are not known until execution starts.
     *
     * @param string $pathname Absolute normalized command file path
     */
    private function isAllowedLazyStrategyCommand(string $pathname): bool
    {
        return str_contains($pathname, DIRECTORY_SEPARATOR . 'RenameByDatePatternCommand.php')
            || str_contains($pathname, DIRECTORY_SEPARATOR . 'RenameByExifDateCommand.php')
            || str_contains($pathname, DIRECTORY_SEPARATOR . 'RenameByPatternCommand.php');
    }
}
