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
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies explicit constructor wiring for the Wave-2 boundary modules.
 *
 * The refactor plan intentionally removes product-level `= new Foo()` defaults
 * from the constructor signatures of Output, Metadata, Reporting, Filesystem,
 * and command-facing boundaries. These modules are now expected to rely on
 * container wiring or explicit test factories instead of silently
 * instantiating their own collaborators.
 *
 * @internal
 */
#[CoversNothing]
final class ConstructorWiringArchitectureTest extends TestCase
{
    /**
     * Verifies that targeted Wave-2 boundary modules do not use constructor
     * parameter defaults that instantiate collaborators directly.
     *
     * The check is intentionally narrow: it only inspects constructor
     * signatures in Output, Metadata, Reporting, Filesystem, and Command.
     * Value-object creation inside methods is still allowed; only hidden
     * constructor wiring defaults are rejected.
     */
    #[Test]
    public function waveTwoBoundariesDoNotInstantiateCollaboratorsInConstructorDefaults(): void
    {
        foreach ($this->waveTwoBoundaryFiles() as $pathname) {
            $contents = file_get_contents($pathname);

            self::assertNotFalse($contents);
            self::assertSame(
                0,
                preg_match('/function\s+__construct\s*\((?:(?!\)\s*\{).)*=\s*new\s+[A-Z_\\\\]/s', $contents),
                sprintf('Unexpected constructor default instantiation in %s', $pathname),
            );
        }
    }

    /**
     * Collects the PHP files belonging to the currently enforced Wave-2
     * boundary modules.
     *
     * @return list<string> Absolute file paths that should obey explicit constructor wiring
     */
    private function waveTwoBoundaryFiles(): array
    {
        $directories = [
            __DIR__ . '/../../src/Command',
            __DIR__ . '/../../src/Service/Output',
            __DIR__ . '/../../src/Metadata',
            __DIR__ . '/../../src/Service/Reporting',
            __DIR__ . '/../../src/Service/Filesystem',
        ];

        $files = [
            __DIR__ . '/../../src/Service/FileSystemService.php',
        ];

        foreach ($directories as $directory) {
            /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo) {
                    continue;
                }

                $pathname = $fileInfo->getPathname();

                if (!str_ends_with($pathname, '.php')) {
                    continue;
                }

                $files[] = $pathname;
            }
        }

        return $this->normalizeAndSortFiles($files);
    }

    /**
     * Normalizes path separators and returns a deterministically ordered file list.
     *
     * @param list<string> $files Absolute file paths collected from the filesystem
     *
     * @return list<string> Normalized and sorted file paths
     */
    private function normalizeAndSortFiles(array $files): array
    {
        $normalizedFiles = [];

        foreach ($files as $file) {
            $normalizedFile = str_replace('\\', DIRECTORY_SEPARATOR, $file);

            if (str_contains($normalizedFile, DIRECTORY_SEPARATOR . '.php-cs-fixer')) {
                continue;
            }

            $normalizedFiles[] = $normalizedFile;
        }

        sort($normalizedFiles);

        return $normalizedFiles;
    }
}
