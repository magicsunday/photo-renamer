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

use function file_get_contents;
use function str_contains;
use function str_ends_with;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies console-boundary rules that are easier to express with lightweight
 * source assertions than with PHPat's dependency builder.
 *
 * The wave-2 architecture explicitly allows direct `SymfonyStyle` usage only in
 * commands, output services, and reporting adapters. Other services must use
 * narrower boundaries such as `ProgressReporterInterface`.
 *
 * @internal
 */
#[CoversNothing]
final class ConsoleBoundaryArchitectureTest extends TestCase
{
    /**
     * Verifies that non-output, non-reporting services do not directly depend
     * on SymfonyStyle.
     *
     * This protects the console boundary after the reporting cleanup by making
     * sure new service classes do not quietly reintroduce direct Symfony
     * Console coupling outside the explicitly allowed folders.
     */
    #[Test]
    public function nonOutputServicesDoNotReferenceSymfonyStyle(): void
    {
        $serviceDirectory = __DIR__ . '/../../src/Service';

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($serviceDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            $pathname = $fileInfo->getPathname();

            if (!str_ends_with($pathname, '.php')) {
                continue;
            }

            if ($this->isAllowedSymfonyStyleService($pathname)) {
                continue;
            }

            $contents = file_get_contents($pathname);

            self::assertNotFalse($contents);
            self::assertFalse(
                str_contains($contents, SymfonyStyle::class),
                sprintf('Unexpected SymfonyStyle dependency in %s', $pathname),
            );
        }
    }

    /**
     * Returns whether a service file is explicitly allowed to know SymfonyStyle.
     *
     * Allowed boundaries are:
     * - `src/Service/Output/*`
     * - `src/Service/Reporting/*`
     * - `src/Service/RenameOutputRenderer.php`
     *
     * @param string $pathname Absolute path to the inspected service PHP file
     *
     * @return bool True when the service belongs to an allowed console boundary
     */
    private function isAllowedSymfonyStyleService(string $pathname): bool
    {
        $normalizedPath = str_replace('\\', DIRECTORY_SEPARATOR, $pathname);

        return str_contains($normalizedPath, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR)
            || str_contains($normalizedPath, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'Reporting' . DIRECTORY_SEPARATOR)
            || str_ends_with($normalizedPath, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'RenameOutputRenderer.php');
    }
}
