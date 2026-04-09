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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function file_get_contents;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the explicit analyzer boundary from the Wave-2 architecture plan.
 *
 * Analyzers are meant to inspect existing state and derive findings. They must
 * stay free from console, filesystem, and process boundaries so they remain
 * deterministic and easy to reuse from commands, planners, and tests.
 *
 * @internal
 */
#[CoversNothing]
final class AnalyzerBoundaryArchitectureTest extends TestCase
{
    /**
     * Verifies that analyzer classes do not directly depend on console,
     * filesystem, or process boundaries.
     *
     * This complements the broader namespace rules with a role-specific guard:
     * analyzers may compute findings, but they must not render, mutate the
     * filesystem, or shell out to external tools.
     */
    #[Test]
    public function analyzersDoNotDependOnConsoleFilesystemOrProcessBoundaries(): void
    {
        foreach ($this->analyzerFiles() as $pathname) {
            $contents = file_get_contents($pathname);

            self::assertNotFalse($contents);
            self::assertFalse(
                str_contains($contents, SymfonyStyle::class),
                sprintf('Unexpected SymfonyStyle dependency in %s', $pathname),
            );
            self::assertFalse(
                str_contains($contents, Filesystem::class),
                sprintf('Unexpected Filesystem dependency in %s', $pathname),
            );
            self::assertFalse(
                str_contains($contents, Process::class),
                sprintf('Unexpected Process dependency in %s', $pathname),
            );
        }
    }

    /**
     * Collects current analyzer-role source files across the product
     * boundaries.
     *
     * @return list<string> Absolute normalized file paths for analyzer classes.
     */
    private function analyzerFiles(): array
    {
        $directories = [
            __DIR__ . '/../../src/Service',
            __DIR__ . '/../../src/Metadata',
            __DIR__ . '/../../src/Helper',
        ];

        $files = [];

        foreach ($directories as $directory) {
            /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo) {
                    continue;
                }

                $pathname = str_replace('\\', DIRECTORY_SEPARATOR, $fileInfo->getPathname());

                if (str_ends_with($pathname, 'Analyzer.php')) {
                    $files[] = $pathname;
                }
            }
        }

        return $files;
    }
}
