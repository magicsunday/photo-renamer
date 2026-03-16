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
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function getcwd;
use function ltrim;
use function rtrim;

use const DIRECTORY_SEPARATOR;

#[CoversClass(AbstractRenameCommand::class)]
final class AbstractRenameCommandTest extends TestCase
{
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
            ->method('setSourceDirectory')
            ->with('/source-directory')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setTargetDirectory')
            ->with('/source-directory')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setListAll')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setUseFileExtensionFromSource')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iteratorOne),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(self::identicalTo($fileDuplicateCollection))
            ->willReturn($fileDuplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                true,
                false,
                false,
                false,
            );

        $command = new class($fileSystemService, $duplicateDetectionService, $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService);

                $this->setName('test:rename');
            }

            protected function getTargetFilenameProcessor(): RenameStrategyInterface
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
            'source-directory' => '/source-directory',
            '--dry-run'        => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Performing dry run', $tester->getDisplay());
    }

    #[Test]
    public function executeAllowsSkipDuplicatesWithoutExplicitTarget(): void
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
            ->method('setSourceDirectory')
            ->with('/source-directory')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setTargetDirectory')
            ->with('/source-directory')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setListAll')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setUseFileExtensionFromSource')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iteratorOne),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(self::identicalTo($fileDuplicateCollection))
            ->willReturn($fileDuplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                true,
                true,
                false,
                false,
            );

        $command = new class($fileSystemService, $duplicateDetectionService, $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService);

                $this->setName('test:rename');
            }

            protected function getTargetFilenameProcessor(): RenameStrategyInterface
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
            'source-directory'  => '/source-directory',
            '--dry-run'         => true,
            '--skip-duplicates' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

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
            ->method('setSourceDirectory')
            ->with('/source-directory')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setTargetDirectory')
            ->with('/target-directory')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setListAll')
            ->with(true)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setUseFileExtensionFromSource')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(self::identicalTo($fileDuplicateCollection))
            ->willReturn($fileDuplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                false,
                false,
                false,
                true,
            );

        $command = new class($fileSystemService, $duplicateDetectionService, $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService);

                $this->setName('test:rename');
            }

            protected function getTargetFilenameProcessor(): RenameStrategyInterface
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
            'source-directory' => '/source-directory',
            'target-directory' => '/target-directory',
            '--list-all'       => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

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
        $expectedTarget = $this->buildExpectedAbsolutePath('relative-target');

        $fileSystemService
            ->expects(self::once())
            ->method('createFileIterator')
            ->with($expectedSource)
            ->willReturn($iteratorOne);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setSourceDirectory')
            ->with(self::callback(function (string $path) use ($expectedSource): bool {
                self::assertSame($expectedSource, $path);

                return true;
            }))
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setTargetDirectory')
            ->with(self::callback(function (string $path) use ($expectedTarget): bool {
                self::assertSame($expectedTarget, $path);

                return true;
            }))
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setListAll')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setUseFileExtensionFromSource')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iteratorOne),
                self::identicalTo($renameStrategy),
                self::identicalTo($duplicateIdentifierStrategy),
            )
            ->willReturn($fileDuplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(self::identicalTo($fileDuplicateCollection))
            ->willReturn($fileDuplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($fileDuplicateCollection),
                true,
                false,
                false,
                false,
            );

        $command = new class($fileSystemService, $duplicateDetectionService, $renameStrategy, $duplicateIdentifierStrategy) extends AbstractRenameCommand {
            public function __construct(
                FileSystemServiceInterface $fileSystemService,
                DuplicateDetectionServiceInterface $duplicateDetectionService,
                private readonly RenameStrategyInterface $renameStrategy,
                private readonly DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
            ) {
                parent::__construct($fileSystemService, $duplicateDetectionService);

                $this->setName('test:rename');
            }

            protected function getTargetFilenameProcessor(): RenameStrategyInterface
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
            'source-directory' => 'relative-source',
            'target-directory' => 'relative-target',
            '--dry-run'        => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
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
