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
 * Verifies the explicit policy boundary introduced by the Wave-2 plan.
 *
 * Policies are meant to encode focused business rules without becoming hidden
 * execution boundaries. This test keeps them free from console, filesystem, and
 * process dependencies that belong to commands, reporting adapters, or lower
 * I/O services instead.
 *
 * @internal
 */
#[CoversNothing]
final class PolicyBoundaryArchitectureTest extends TestCase
{
    /**
     * Verifies that policy classes do not directly depend on console,
     * filesystem, or process boundaries.
     *
     * This preserves the intended role split where policies decide, but do not
     * render, move files, or spawn external commands.
     */
    #[Test]
    public function policiesDoNotDependOnConsoleFilesystemOrProcessBoundaries(): void
    {
        foreach ($this->policyFiles() as $pathname) {
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
     * Collects current policy-role source files across the product boundaries.
     *
     * The inspection is suffix-based because the Wave-2 role language applies
     * to architectural intent rather than to one namespace subtree.
     *
     * @return list<string> Absolute normalized file paths of current policy classes.
     */
    private function policyFiles(): array
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

                if (str_ends_with($pathname, 'Policy.php')) {
                    $files[] = $pathname;
                }
            }
        }

        return $files;
    }
}
