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
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Wires real runtime executors and mutation scopes for synthetic filesystem tests.
 *
 * The fixture constructs the same shared mover/journal graph as production DI;
 * callers receive the particular executor they exercise directly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RuntimeExecutorFactory
{
    /**
     * Creates the legacy executor with its renderer and shared mutation journal.
     *
     * @param RenameOutputRenderer|null $renderer Optional renderer for output assertions
     * @param SymfonyStyle|null         $io       Optional console used by the progress reporter
     *
     * @return LegacyRenameExecutor Real legacy executor for synthetic file mutations
     */
    public static function createLegacy(?RenameOutputRenderer $renderer = null, ?SymfonyStyle $io = null): LegacyRenameExecutor
    {
        $io ??= new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $journal = self::createMutationJournal();

        return new LegacyRenameExecutor(new ConsoleProgressReporter($io), $renderer ?? OutputRendererFactory::create($io), self::createMover($io, $journal), $journal);
    }

    /**
     * Creates the plan executor with its own shared mover and journal.
     *
     * @param SymfonyStyle|null $io Optional console for observed runtime diagnostics
     *
     * @return ExecutionPlanExecutor Real executor for synthetic execution plans
     */
    public static function createPlan(?SymfonyStyle $io = null): ExecutionPlanExecutor
    {
        $io ??= new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $journal = self::createMutationJournal();

        return new ExecutionPlanExecutor(new ConsoleProgressReporter($io), self::createMover($io, $journal), $journal);
    }

    /**
     * Shares the journal with the executor and keeps source observation explicit.
     *
     * @param SymfonyStyle    $io      Console for native mutation diagnostics
     * @param MutationJournal $journal Same journal passed to the enclosing executor
     *
     * @return RuntimeFileMoveExecutor Mover using actual temporary-file operations
     */
    private static function createMover(SymfonyStyle $io, MutationJournal $journal): RuntimeFileMoveExecutor
    {
        return new RuntimeFileMoveExecutor(new ConsoleProgressReporter($io), new Filesystem(), new RuntimeCollisionPathAllocator(), self::createSourceIdentityGuard(), $journal);
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
