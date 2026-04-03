<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\LegacyRenameExecutor;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates fully wired FileSystemService instances for tests.
 *
 * The production container now provides explicit constructor wiring for the
 * filesystem facade and its runtime executors. This fixture keeps manual test
 * setup aligned with that DI shape without duplicating the same nested object
 * graph across unit and integration tests.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class FileSystemServiceFactory
{
    /**
     * Creates a FileSystemService with its concrete filesystem collaborators.
     *
     * @param RenameOutputRenderer $renderer Shared output renderer used by the legacy rename executor
     * @param SymfonyStyle         $io       Console IO used by the progress reporter
     *
     * @return FileSystemService Fully wired filesystem facade instance for tests
     */
    public static function create(RenameOutputRenderer $renderer, SymfonyStyle $io): FileSystemService
    {
        $progressReporter              = new ConsoleProgressReporter($io);
        $filesystem                    = new Filesystem();
        $runtimeCollisionPathAllocator = new RuntimeCollisionPathAllocator();
        $runtimeFileMoveExecutor       = new RuntimeFileMoveExecutor(
            $progressReporter,
            $filesystem,
            $runtimeCollisionPathAllocator,
        );

        return new FileSystemService(
            new FileCollector(),
            new ExecutionPlanExecutor($progressReporter, $runtimeFileMoveExecutor),
            new LegacyRenameExecutor($progressReporter, $renderer, $runtimeFileMoveExecutor),
        );
    }
}
