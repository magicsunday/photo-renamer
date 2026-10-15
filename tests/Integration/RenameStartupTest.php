<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use MagicSunday\Renamer\Application;
use MagicSunday\Renamer\Command\AbstractMediaRenameCommand;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\SourceIdentityGuard;
use MagicSunday\Renamer\Service\Reporting\ConsoleProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function strpos;
use function substr_count;

/**
 * Protects consent-before-read and visible safety verification at the real
 * application boundary, with a real guarded move of disposable source bytes.
 */
#[CoversNothing]
final class RenameStartupTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Refusal/default-no must not hash sources; acceptance must announce the
     * full-read phase before the first hash and still perform the guarded move.
     *
     * @param string $answer      Interactive response
     * @param bool   $interactive Whether Console can ask a question
     * @param bool   $dryRun      Whether the read-only workflow is selected
     * @param int    $expected    Expected exit status
     * @param bool   $holdLock    Whether another batch blocks execution after consent
     */
    #[Test]
    #[DataProvider('startupModes')]
    public function confirmationPrecedesSourceReads(string $answer, bool $interactive, bool $dryRun, int $expected, bool $holdLock): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $source     = $workspace . '/media/source.jpg';
        $target     = $workspace . '/media/renamed.jpg';
        $filesystem->dumpFile($source, 'synthetic source bytes');
        $actualHasher = new SafeHashCalculator();
        $calculator   = self::createMock(SafeHashCalculatorInterface::class);
        $willMove     = ($expected === Command::SUCCESS) && !$dryRun;
        $collector    = new FileCollector(new SafeRegex());
        $guard        = new SourceIdentityGuard($calculator, $collector);
        $command      = new class($collector, new SafeRegex(), $filesystem, $guard, $source, $target) extends AbstractMediaRenameCommand {
            /** Wires the real shared lifecycle to a small observable filesystem action. */
            public function __construct(FileCollector $collector, SafeRegex $regex, Filesystem $filesystem, private readonly SourceIdentityGuard $guard, private readonly string $source, private readonly string $target)
            {
                parent::__construct($collector, $regex, $filesystem);
                $this->setName('rename:exif');
            }

            /** Renames only after the application captured and revalidated the source. */
            #[Override]
            protected function executeCommand(): int
            {
                if (!$this->dryRun) {
                    $this->guard->assertUnchanged($this->source);
                    $this->filesystem->rename($this->source, $this->target);
                }

                return self::SUCCESS;
            }
        };
        $application = new Application([$command], new BatchRunLock($filesystem, $workspace . '/state'), $guard, RuntimeExecutorFactory::createMutationJournal($workspace . '/state'));
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $calculator->expects(self::exactly($willMove ? 2 : 0))->method('hashFile')->willReturnCallback(static function (SplFileInfo $file, string $algorithm) use ($actualHasher, $tester): string {
            $display  = $tester->getDisplay();
            $prompt   = strpos($display, 'Are you sure?');
            $counting = strpos($display, 'Counting source files');
            $phase    = strpos($display, 'Verifying source file safety');
            self::assertNotFalse($prompt);
            self::assertNotFalse($phase);
            self::assertNotFalse($counting);
            self::assertLessThan($counting, $prompt);
            self::assertLessThan($phase, $counting);

            return $actualHasher->hashFile($file, $algorithm);
        });
        $tester->setInputs([$answer]);

        $lock = $holdLock ? new BatchRunLock($filesystem, $workspace . '/state')->acquire() : null;

        try {
            self::assertSame($expected, $tester->run(['command' => 'rename:exif', 'source' => $workspace . '/media', '--dry-run' => $dryRun], ['interactive' => $interactive]));
            $display = $tester->getDisplay();
            self::assertSame($willMove, $filesystem->exists($target));
            self::assertSame('synthetic source bytes', $filesystem->readFile($willMove ? $target : $source));
            self::assertSame($willMove ? 1 : 0, substr_count($display, 'Verifying source file safety'));
            self::assertSame($willMove ? 1 : 0, substr_count($display, 'Counting source files'));
            self::assertSame($dryRun || !$interactive ? 0 : 1, substr_count($display, 'Are you sure?'));

            if ($willMove) {
                self::assertStringContainsString('Counting source files', $display);
                self::assertStringContainsString('1/1', $display);
                self::assertStringContainsString('100%', $display);
                self::assertStringContainsString('Remaining:', $display);
            }

            $lock?->release();
            $direct = new CommandTester($command);
            $direct->setInputs(['no']);
            self::assertSame(Command::FAILURE, $direct->execute(['source' => $workspace . '/media'], ['interactive' => true]));
            self::assertStringContainsString('Are you sure?', $direct->getDisplay());
            self::assertStringNotContainsString('Verifying source file safety', $direct->getDisplay());
        } finally {
            $lock?->release();
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Counting uses the fixed candidate inventory without reading contents.
     * A file arriving during hashing stays outside that inventory and cannot
     * acquire mutation authority; empty inputs do not invent a progress total.
     *
     * @param int  $files      Number of existing nested source files
     * @param bool $singleFile Whether only one source is selected
     */
    #[Test]
    #[DataProvider('inventoryModes')]
    public function countedInventoryHasTruthfulProgress(int $files, bool $singleFile): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $filesystem->mkdir($workspace . '/nested with spaces');

        for ($index = 0; $index < $files; ++$index) {
            $filesystem->dumpFile($workspace . '/nested with spaces/' . $index . '.jpg', 'synthetic bytes');
        }

        $output       = new BufferedOutput();
        $reporter     = new ConsoleProgressReporter(new SymfonyStyle(new ArrayInput([]), $output));
        $expected     = $singleFile ? 1 : $files;
        $late         = $workspace . '/late.jpg';
        $calculator   = self::createMock(SafeHashCalculatorInterface::class);
        $actualHasher = new SafeHashCalculator();
        $calculator->expects(self::exactly($expected))->method('hashFile')->willReturnCallback(static function (SplFileInfo $file, string $algorithm) use ($output, $filesystem, $late, $actualHasher): string {
            $display = $output->fetch();
            $output->write($display);
            self::assertStringContainsString('Counting source files', $display);
            self::assertStringContainsString('Verifying source file safety', $display);
            $filesystem->dumpFile($late, 'late synthetic source');

            return $actualHasher->hashFile($file, $algorithm);
        });
        $guard = new SourceIdentityGuard($calculator, new FileCollector(new SafeRegex()));

        try {
            $guard->begin($singleFile ? $workspace . '/nested with spaces/0.jpg' : $workspace, $reporter);
            $display = $output->fetch();

            if ($expected === 0) {
                self::assertStringContainsString('No source files found.', $display);
                self::assertStringNotContainsString('100%', $display);
                self::assertStringNotContainsString('Verifying source file safety', $display);
                $filesystem->dumpFile($late, 'late synthetic source');
            } else {
                self::assertStringContainsString($expected . '/' . $expected, $display);
                self::assertStringContainsString('100%', $display);
                self::assertStringContainsString('Elapsed:', $display);
                self::assertStringContainsString('Remaining:', $display);
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/was not verified before analysis/');
            $guard->assertUnchanged($late);
        } finally {
            $guard->finish();
            $this->removeWorkspace($workspace);
        }
    }

    /** @return iterable<string, array{int, bool}> Inventory boundaries */
    public static function inventoryModes(): iterable
    {
        yield 'nested directory' => [3, false];
        yield 'single file only' => [3, true];
        yield 'empty directory' => [0, false];
    }

    /** @return iterable<string, array{string, bool, bool, int, bool}> Distinct consent policies */
    public static function startupModes(): iterable
    {
        yield 'confirmed' => ['yes', true, false, Command::SUCCESS, false];
        yield 'declined' => ['no', true, false, Command::FAILURE, false];
        yield 'default no' => ['', true, false, Command::FAILURE, false];
        yield 'noninteractive default no' => ['', false, false, Command::FAILURE, false];
        yield 'lock failure after consent' => ['yes', true, false, Command::FAILURE, true];
        yield 'dry run' => ['', true, true, Command::SUCCESS, false];
    }
}
