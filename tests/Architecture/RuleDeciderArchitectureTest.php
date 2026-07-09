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
 * Verifies the explicit Rule/Decider boundary introduced in Wave 2.
 *
 * Rules and deciders may encode priority and decision shaping, but they must
 * not quietly grow console, filesystem, or process dependencies.
 *
 * @internal
 */
#[CoversNothing]
final class RuleDeciderArchitectureTest extends TestCase
{
    /**
     * Verifies that rule and decider classes do not reference console,
     * filesystem, or process boundaries directly.
     */
    #[Test]
    public function rulesAndDecidersDoNotDependOnConsoleFilesystemOrProcessBoundaries(): void
    {
        foreach ($this->ruleAndDeciderFiles() as $pathname) {
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
     * Collects source files whose class or interface names indicate Rule/Decider roles.
     *
     * @return list<string> Absolute file paths for current rule/decider classes
     */
    private function ruleAndDeciderFiles(): array
    {
        $serviceDirectory = __DIR__ . '/../../src/Service';
        $files            = [];

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($serviceDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            $pathname = str_replace('\\', DIRECTORY_SEPARATOR, $fileInfo->getPathname());

            if (!str_ends_with($pathname, '.php')) {
                continue;
            }

            if (
                str_ends_with($pathname, 'Rule.php')
                || str_ends_with($pathname, 'RuleInterface.php')
                || str_ends_with($pathname, 'Decider.php')
                || str_ends_with($pathname, 'DeciderInterface.php')
            ) {
                $files[] = $pathname;
            }
        }

        return $files;
    }
}
