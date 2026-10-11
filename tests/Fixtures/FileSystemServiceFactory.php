<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\LegacyRenameExecutor;
use MagicSunday\Renamer\Service\Filesystem\MutationJournal;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Filesystem\SourceIdentityGuard;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
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
        $journal                       = self::createMutationJournal();
        $runtimeFileMoveExecutor       = new RuntimeFileMoveExecutor(
            $progressReporter,
            $filesystem,
            $runtimeCollisionPathAllocator,
            self::createSourceIdentityGuard(),
            $journal,
        );

        return new FileSystemService(
            new FileCollector(new SafeRegex()),
            new ExecutionPlanExecutor($progressReporter, $runtimeFileMoveExecutor, $journal),
            new LegacyRenameExecutor($progressReporter, $renderer, $runtimeFileMoveExecutor, $journal),
        );
    }

    /**
     * Supplies a fresh run-scoped source guard with its own hash calculator.
     * Tests exercising the application and mover together pass the same returned
     * instance to both so pre-analysis identity cannot be captured too late.
     *
     * @return SourceIdentityGuard Explicitly wired, initially inactive source scope
     */
    public static function createSourceIdentityGuard(): SourceIdentityGuard
    {
        return new SourceIdentityGuard(new SafeHashCalculator(), new FileCollector(new SafeRegex()));
    }

    /**
     * Supplies an inactive journal for internal service tests, or a shared
     * instance for application tests that explicitly begin the CLI scope.
     *
     * @param string          $stateDirectory Synthetic persistent state base used only when the scope begins
     * @param Filesystem|null $filesystem     Optional filesystem boundary for controlled persistence failures
     *
     * @return MutationJournal Fresh journal with explicit storage collaborators
     */
    public static function createMutationJournal(string $stateDirectory = '.build/cache', ?Filesystem $filesystem = null): MutationJournal
    {
        $filesystem ??= new Filesystem();

        return new MutationJournal($filesystem, new PrivateCacheStorage($filesystem), $stateDirectory);
    }
}
