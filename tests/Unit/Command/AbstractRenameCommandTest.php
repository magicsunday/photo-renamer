<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\AbstractRenameCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\LegacyRenameExecutor;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function basename;
use function chdir;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function ltrim;
use function mkdir;
use function rtrim;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Verifies the AbstractRenameCommand base class, which provides the shared
 * execution pipeline for all rename commands: argument parsing, directory
 * normalisation, service orchestration, and option propagation.
 *
 * Each test instantiates an anonymous subclass that wires stub strategies,
 * isolating the base class behaviour from any concrete command implementation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class AbstractRenameCommandTest extends TestCase
{
    use WorkspaceTrait;

    /** Buffered legacy output kept distinct from the command preview. */
    private BufferedOutput $renderOutput;

    /** Synthetic source tree used by the actual collector. */
    private string $workspace;

    /** Working directory restored after relative-path CLI checks. */
    private string $workingDirectory;

    /** Creates a real empty tree and a relative child without using media files. */
    #[Override]
    protected function setUp(): void
    {
        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);
        $this->workingDirectory = $workingDirectory;
        $this->workspace        = $this->createTempWorkspace();
        new Filesystem()->mkdir($this->workspace . '/relative-source');
        chdir($this->workspace);
    }

    /** Restores process cwd before removing this test's synthetic source tree. */
    #[Override]
    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->removeWorkspace($this->workspace);
    }

    /**
     * A dry-run scans a real source tree and renders its planned target while
     * keeping source bytes intact and leaving the target absent.
     */
    #[Test]
    public function executeDryRunPreservesSourceAndReportsPreview(): void
    {
        $fileCollector             = new FileCollector(new SafeRegex());
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $fileDuplicateCollection = new FileDuplicateCollection();
        $path                    = $this->workspace . '/Photo.jpg';
        file_put_contents($path, 'selected bytes');
        $target        = $this->workspace . '/renamed.jpg';
        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target))->addRename(new Rename(new SplFileInfo($path), new SplFileInfo($target)));
        $fileDuplicateCollection->set('group', $fileDuplicate);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
                $this->workspace,
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                $this->workspace,
                false,
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $command = $this->createBaseCommand(
            $fileCollector,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => $this->workspace,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Performing dry run', $tester->getDisplay());
        self::assertSame('selected bytes', file_get_contents($path));
        self::assertFileDoesNotExist($target);
        self::assertStringContainsString('renamed.jpg', $this->renderOutput->fetch());
    }

    /**
     * Listing all entries includes a canonical no-op in the actual legacy
     * output and preserves its bytes after interactive confirmation.
     */
    #[Test]
    public function executeListAllShowsOriginalWithoutChangingIt(): void
    {
        $fileCollector             = new FileCollector(new SafeRegex());
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $fileDuplicateCollection = new FileDuplicateCollection();
        $path                    = $this->workspace . '/Photo.jpg';
        file_put_contents($path, 'selected bytes');
        $target        = $path;
        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target))->addRename(new Rename(new SplFileInfo($path), new SplFileInfo($target)));
        $fileDuplicateCollection->set('group', $fileDuplicate);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
                self::anything(),
                self::anything(),
                $this->workspace,
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                $this->workspace,
                false,
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $command = $this->createBaseCommand(
            $fileCollector,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute([
            'source'     => $this->workspace,
            '--list-all' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $this->renderOutput->fetch();
        self::assertStringContainsString('[O]', $display);
        self::assertMatchesRegularExpression('/Already correctly named\s+1/', $display);
        self::assertStringContainsString('Rename run complete: 0 file(s) renamed.', $display);
        self::assertSame('selected bytes', file_get_contents($path));
    }

    /**
     * Verifies that the default output filter includes all actionable tags but
     * suppresses unchanged original entries.
     */
    #[Test]
    public function executeUsesDefaultShowFilterWithoutOriginalEntries(): void
    {
        $fileCollector             = new FileCollector(new SafeRegex());
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $fileDuplicateCollection = new FileDuplicateCollection();
        $path                    = $this->workspace . '/Photo.jpg';
        file_put_contents($path, 'selected bytes');
        $target        = $path;
        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($target))->addRename(new Rename(new SplFileInfo($path), new SplFileInfo($target)));
        $fileDuplicateCollection->set('group', $fileDuplicate);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                $this->workspace,
                false,
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('clearHashCache');

        [
            OutputEntryTag::Candidate->letter(),
            OutputEntryTag::Review->letter(),
            OutputEntryTag::Rename->letter(),
            OutputEntryTag::Fallback->letter(),
            OutputEntryTag::Duplicate->letter(),
            OutputEntryTag::Warning->letter(),
            OutputEntryTag::Skipped->letter(),
            OutputEntryTag::Error->letter(),
            OutputEntryTag::Info->letter(),
        ];

        $command = $this->createBaseCommand(
            $fileCollector,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => $this->workspace,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('[O]', $this->renderOutput->fetch());
        self::assertSame('selected bytes', file_get_contents($path));
    }

    /**
     * Verifies that the interactive confirmation defaults to "no" when the
     * command would modify files.
     */
    #[Test]
    public function executeDefaultsConfirmationToNoForNonDryRuns(): void
    {
        $fileCollector             = new FileCollector(new SafeRegex());
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $duplicateDetectionService
            ->expects(self::never())
            ->method('groupFilesByDuplicateIdentifier');

        $duplicateDetectionService
            ->expects(self::never())
            ->method('createDuplicateFilenames');

        $duplicateDetectionService
            ->expects(self::never())
            ->method('clearHashCache');

        $command = $this->createBaseCommand(
            $fileCollector,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['']);
        $tester->execute([
            'source' => $this->workspace,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[no]', $tester->getDisplay());
    }

    /**
     * Verifies that a relative source directory argument is resolved to an absolute path
     * by prepending the current working directory before being passed to the services.
     */
    #[Test]
    public function executeNormalizesRelativeDirectoryArguments(): void
    {
        $fileCollector             = new FileCollector(new SafeRegex());
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $fileDuplicateCollection = new FileDuplicateCollection();

        $expectedSource = $this->buildExpectedAbsolutePath('relative-source');

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
                self::callback(
                    static fn (string $sourceDirectory): bool => self::assertNormalizedPathEquals($expectedSource, $sourceDirectory)
                ),
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                self::callback(
                    static fn (string $sourceDirectory): bool => self::assertNormalizedPathEquals($expectedSource, $sourceDirectory)
                ),
                false,
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $command = $this->createBaseCommand(
            $fileCollector,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => 'relative-source',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * Verifies that trailing slashes are removed from unresolved relative source
     * directories before the normalized path is passed to the services.
     */
    #[Test]
    public function executeTrimsTrailingSlashesFromRelativeDirectoryArguments(): void
    {
        $fileCollector             = new FileCollector(new SafeRegex());
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $fileDuplicateCollection = new FileDuplicateCollection();

        $expectedSource = $this->buildExpectedAbsolutePath('relative-source');

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
                self::callback(
                    static fn (string $sourceDirectory): bool => self::assertNormalizedPathEquals($expectedSource, $sourceDirectory)
                ),
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                self::callback(
                    static fn (string $sourceDirectory): bool => self::assertNormalizedPathEquals($expectedSource, $sourceDirectory)
                ),
                false,
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $command = $this->createBaseCommand(
            $fileCollector,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => 'relative-source///',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * Verifies that the base createFileIterator() throws a RuntimeException when the
     * source directory does not exist, and that the pipeline catches it and returns FAILURE.
     */
    #[Test]
    public function executeReturnsFailureWhenSourceDirectoryDoesNotExist(): void
    {
        $fileCollector             = new FileCollector(new SafeRegex());
        $duplicateDetectionService = self::createStub(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        // No setter stubs needed — directories are now method parameters

        $command = $this->createBaseCommand(
            $fileCollector,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => '/nonexistent-directory-for-test',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    /**
     * Verifies that single-file mode only forwards the exact requested filename
     * to the recursive filter. On case-sensitive filesystems, `Photo.jpg` and
     * `photo.jpg` can coexist and must not both be processed.
     */
    #[Test]
    public function executeSingleFileModeMatchesExactFilename(): void
    {
        $filesystem = new Filesystem();
        $workspace  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'renamer-single-file-' . uniqid();
        $sourceFile = $workspace . DIRECTORY_SEPARATOR . 'Photo.jpg';

        mkdir($workspace);
        file_put_contents($sourceFile, 'selected');
        file_put_contents($workspace . DIRECTORY_SEPARATOR . 'photo.jpg', 'other');

        try {
            $fileCollector             = new FileCollector(new SafeRegex());
            $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

            $renameStrategy              = self::createStub(RenameStrategyInterface::class);
            $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

            $fileDuplicateCollection = new FileDuplicateCollection();

            $duplicateDetectionService
                ->expects(self::once())
                ->method('groupFilesByDuplicateIdentifier')
                ->with(self::callback(static function (RecursiveIteratorIterator $iterator) use ($sourceFile): bool {
                    $matches = [];

                    /** @var SplFileInfo $file */
                    foreach ($iterator as $file) {
                        $matches[] = $file->getFilename();
                    }

                    self::assertSame([basename($sourceFile)], $matches);

                    return true;
                }), self::anything(), self::anything(), $workspace)
                ->willReturn($fileDuplicateCollection);

            $duplicateDetectionService
                ->expects(self::once())
                ->method('createDuplicateFilenames')
                ->willReturn($fileDuplicateCollection);

            $command = $this->createBaseCommand(
                $fileCollector,
                $duplicateDetectionService,
                $renameStrategy,
                $duplicateIdentifierStrategy,
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'source'    => $sourceFile,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    private function buildExpectedAbsolutePath(string $relativePath): string
    {
        $workingDirectory = getcwd();

        self::assertIsString($workingDirectory, 'Unable to determine the working directory for path normalization assertions.');

        $trimmedWorkingDirectory = rtrim($workingDirectory, '/\\');

        if ($trimmedWorkingDirectory === '') {
            return '/' . ltrim($relativePath, '/');
        }

        return $trimmedWorkingDirectory . '/' . ltrim($relativePath, '/');
    }

    private static function assertNormalizedPathEquals(string $expectedPath, string $actualPath): bool
    {
        self::assertSame(
            self::normalizePathForAssertion($expectedPath),
            self::normalizePathForAssertion($actualPath),
        );

        return true;
    }

    private static function normalizePathForAssertion(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function createBaseCommand(
        FileCollector $fileCollector,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
    ): AbstractRenameCommand {
        $this->renderOutput = new BufferedOutput();
        $io                 = new SymfonyStyle(new ArrayInput([]), $this->renderOutput);

        return new class($fileCollector, RuntimeExecutorFactory::createLegacy(io: $io), $duplicateDetectionService, new SafeRegex(), new Filesystem(), $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileCollector $fileCollector,
                LegacyRenameExecutor $legacyRenameExecutor,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                SafeRegex $safeRegex,
                Filesystem $filesystem,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileCollector, $legacyRenameExecutor, $duplicateDetectionService, $safeRegex, $filesystem);

                $this->setName('test:rename');
            }

            protected function getTargetFilenameStrategy(): RenameStrategyInterface
            {
                return $this->renameStrategy;
            }

            protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
            {
                return $this->duplicateIdentifierStrategy;
            }
        };
    }
}
