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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function getcwd;
use function ltrim;
use function rtrim;

use const DIRECTORY_SEPARATOR;

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
#[CoversClass(AbstractRenameCommand::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(RenameOptions::class)]
#[UsesClass(RenameResult::class)]
#[UsesClass(OutputEntryTag::class)]
final class AbstractRenameCommandTest extends TestCase
{
    /**
     * Verifies the full execution flow: the command creates a file iterator from
     * the source directory, passes it through grouping and duplicate detection,
     * and finally hands the collection to renameFiles() with the correct options.
     *
     * The mock expectations ensure that every service method is called exactly once
     * with the expected arguments, and that --dry-run is propagated to RenameOptions.
     */
    #[Test]
    public function executeUsesInterfacesForDependencies(): void
    {
        $fileSystemService         = $this->createMock(FileSystemServiceInterface::class);
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $iteratorOne             = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $fileDuplicateCollection = new FileDuplicateCollection();

        $fileSystemService
            ->expects(self::once())
            ->method('createFileIterator')
            ->with('/source-directory')
            ->willReturn($iteratorOne);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iteratorOne),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
                '/source-directory',
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                '/source-directory',
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                self::callback(static function (RenameOptions $options): bool {
                    self::assertTrue($options->dryRun);
                    self::assertFalse($options->listAll);

                    return true;
                }),
            );

        $command = new class($fileSystemService, $duplicateDetectionService, new SafeRegex(), $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                SafeRegex $safeRegex,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService, $safeRegex);

                $this->setName('test:rename');
            }

            protected function createFileIterator(): RecursiveIteratorIterator
            {
                return $this->fileSystemService->createFileIterator($this->sourceDirectory);
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

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => '/source-directory',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Performing dry run', $tester->getDisplay());
    }

    /**
     * Verifies that the --list-all flag is propagated through RenameOptions to
     * the FileSystemService.
     *
     * With --list-all the output includes canonical entries ([O] prefix) alongside
     * renames and duplicates, so the flag must arrive at renameFiles().
     */
    #[Test]
    public function executePropagatesListAllFlagToServices(): void
    {
        $fileSystemService         = $this->createMock(FileSystemServiceInterface::class);
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $iterator                = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $fileDuplicateCollection = new FileDuplicateCollection();

        $fileSystemService
            ->expects(self::once())
            ->method('createFileIterator')
            ->with('/source-directory')
            ->willReturn($iterator);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::anything(),
                self::anything(),
                '/source-directory',
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                '/source-directory',
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                self::callback(static function (RenameOptions $options): bool {
                    self::assertFalse($options->dryRun);
                    self::assertTrue($options->listAll);

                    return true;
                }),
            );

        $command = new class($fileSystemService, $duplicateDetectionService, new SafeRegex(), $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                SafeRegex $safeRegex,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService, $safeRegex);

                $this->setName('test:rename');
            }

            protected function createFileIterator(): RecursiveIteratorIterator
            {
                return $this->fileSystemService->createFileIterator($this->sourceDirectory);
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

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute([
            'source'     => '/source-directory',
            '--list-all' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * Verifies that a relative source directory argument is resolved to an absolute path
     * by prepending the current working directory before being passed to the services.
     */
    #[Test]
    public function executeNormalizesRelativeDirectoryArguments(): void
    {
        $fileSystemService         = $this->createMock(FileSystemServiceInterface::class);
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $iteratorOne             = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $fileDuplicateCollection = new FileDuplicateCollection();

        $expectedSource = $this->buildExpectedAbsolutePath('relative-source');

        $fileSystemService
            ->expects(self::once())
            ->method('createFileIterator')
            ->with($expectedSource)
            ->willReturn($iteratorOne);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iteratorOne),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
                $expectedSource,
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                $expectedSource,
                false,
            )
            ->willReturn($fileDuplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                self::callback(static function (RenameOptions $options): bool {
                    self::assertTrue($options->dryRun);
                    self::assertFalse($options->listAll);

                    return true;
                }),
            );

        $command = new class($fileSystemService, $duplicateDetectionService, new SafeRegex(), $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                SafeRegex $safeRegex,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService, $safeRegex);

                $this->setName('test:rename');
            }

            protected function createFileIterator(): RecursiveIteratorIterator
            {
                return $this->fileSystemService->createFileIterator($this->sourceDirectory);
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

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => 'relative-source',
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
        $fileSystemService         = self::createStub(FileSystemServiceInterface::class);
        $duplicateDetectionService = self::createStub(DuplicateDetectionServiceInterface::class);

        $renameStrategy              = self::createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        // No setter stubs needed — directories are now method parameters

        $command = new class($fileSystemService, $duplicateDetectionService, new SafeRegex(), $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                SafeRegex $safeRegex,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService, $safeRegex);

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

        $tester = new CommandTester($command);
        $tester->execute([
            'source'    => '/nonexistent-directory-for-test',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    private function buildExpectedAbsolutePath(string $relativePath): string
    {
        $workingDirectory = getcwd();

        self::assertIsString($workingDirectory, 'Unable to determine the working directory for path normalization assertions.');

        $trimmedWorkingDirectory = rtrim($workingDirectory, '/\\');

        if ($trimmedWorkingDirectory === '') {
            return DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        }

        return $trimmedWorkingDirectory . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    }
}
