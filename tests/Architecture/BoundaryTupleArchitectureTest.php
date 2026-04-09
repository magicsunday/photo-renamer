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
use function preg_match_all;
use function sprintf;
use function str_ends_with;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Detects tuple-style shape arrays at public/protected Service, Metadata, and
 * Helper boundaries.
 *
 * Normal homogeneous arrays remain allowed. This test only protects against
 * leaking anonymous `array{...}` contracts from public/protected methods
 * across those boundaries, with a small allowlist for explicit persistence
 * serialization payloads.
 *
 * @internal
 */
#[CoversNothing]
final class BoundaryTupleArchitectureTest extends TestCase
{
    /**
     * Verifies that public/protected boundary methods do not expose shape-array
     * return contracts outside the narrow serialization allowlist.
     */
    #[Test]
    public function publicAndProtectedBoundaryMethodsDoNotExposeShapeArrayReturns(): void
    {
        foreach ($this->boundaryFiles() as $pathname) {
            if ($this->isAllowedShapeArrayBoundary($pathname)) {
                continue;
            }

            $contents = file_get_contents($pathname);

            self::assertNotFalse($contents);
            self::assertSame(
                0,
                preg_match_all('/@return\s+(?:list<)?array\{[\s\S]*?\*\/\s*(?:public|protected)\s+function/', $contents),
                sprintf('Unexpected public/protected shape-array return contract in %s', $pathname),
            );
        }
    }

    /**
     * Collects Service, Metadata, and Helper files that participate in boundary contracts.
     *
     * @return list<string> Absolute PHP file paths to inspect
     */
    private function boundaryFiles(): array
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

                $pathname = $fileInfo->getPathname();

                if (str_ends_with($pathname, '.php')) {
                    $files[] = str_replace('\\', DIRECTORY_SEPARATOR, $pathname);
                }
            }
        }

        return $files;
    }

    /**
     * Returns whether the file is an explicit allowlisted serialization boundary.
     *
     * @param string $pathname Absolute normalized file path
     */
    private function isAllowedShapeArrayBoundary(string $pathname): bool
    {
        return str_ends_with($pathname, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Metadata' . DIRECTORY_SEPARATOR . 'MetadataCacheEntry.php');
    }
}
