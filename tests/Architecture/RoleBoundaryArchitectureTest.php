<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Architecture;

use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataExtractor;
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
 * Verifies role-specific boundary rules that go beyond broad namespace
 * direction.
 *
 * Wave 2 explicitly distinguishes presentational renderers from execution and
 * metadata boundaries, and it keeps planners free from direct console or file-
 * operation dependencies. These rules are easier to express as lightweight
 * source assertions than as PHPat dependency chains.
 *
 * @internal
 */
#[CoversNothing]
final class RoleBoundaryArchitectureTest extends TestCase
{
    /**
     * Verifies that renderer classes stay presentation-only and do not quietly
     * grow metadata extraction, strategy, or process execution dependencies.
     *
     * This protects the output boundary after the renderer split: renderers may
     * format and project operator-facing information, but they must not start
     * deciding through strategy injection, reading metadata directly, or
     * shelling out to external processes.
     */
    #[Test]
    public function renderersDoNotDependOnMetadataStrategiesOrProcessExecution(): void
    {
        foreach ($this->roleFiles('Renderer.php') as $pathname) {
            $contents = file_get_contents($pathname);

            self::assertNotFalse($contents);
            self::assertFalse(
                str_contains($contents, MetadataExtractor::class),
                sprintf('Unexpected MetadataExtractor dependency in %s', $pathname),
            );
            self::assertFalse(
                str_contains($contents, ExifMetadataProvider::class),
                sprintf('Unexpected ExifMetadataProvider dependency in %s', $pathname),
            );
            self::assertFalse(
                str_contains($contents, 'MagicSunday\\Renamer\\Strategy\\'),
                sprintf('Unexpected Strategy dependency in %s', $pathname),
            );
            self::assertFalse(
                str_contains($contents, Process::class),
                sprintf('Unexpected Process dependency in %s', $pathname),
            );
        }
    }

    /**
     * Verifies that planner classes remain semantic planning collaborators
     * rather than console- or filesystem-aware execution boundaries.
     *
     * Planners may derive write intentions and choose between domain-level
     * options, but the actual console output and file/process execution must
     * stay outside this role to preserve testability and clear orchestration.
     */
    #[Test]
    public function plannersDoNotDependOnConsoleFilesystemOrProcessBoundaries(): void
    {
        foreach ($this->roleFiles('Planner.php') as $pathname) {
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
     * Collects PHP source files that currently implement a specific role suffix.
     *
     * The check is intentionally suffix-based because the architecture rules are
     * defined in terms of role names such as `Renderer` and `Planner`, not in
     * terms of one particular namespace subtree.
     *
     * @param string $suffix File suffix identifying the current architectural role.
     *
     * @return list<string> Absolute normalized file paths to inspect.
     */
    private function roleFiles(string $suffix): array
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

                if (str_ends_with($pathname, $suffix)) {
                    $files[] = $pathname;
                }
            }
        }

        return $files;
    }
}
