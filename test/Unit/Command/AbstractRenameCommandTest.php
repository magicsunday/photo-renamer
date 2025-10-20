<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use RecursiveArrayIterator;
use MagicSunday\Renamer\Command\AbstractRenameCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AbstractRenameCommand::class)]
final class AbstractRenameCommandTest extends TestCase
{
    #[Test]
    public function executeUsesInterfacesForDependencies(): void
    {
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy = $this->createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = $this->createStub(DuplicateIdentifierStrategyInterface::class);

        $iteratorOne = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $iteratorTwo = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $fileDuplicateCollection = new FileDuplicateCollection();

        $fileSystemService
            ->expects(self::exactly(2))
            ->method('createFileIterator')
            ->with('/source-directory')
            ->willReturnOnConsecutiveCalls($iteratorOne, $iteratorTwo);

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
            ->method('setUseFileExtensionFromSource')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
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
            );

        $command = new class(
            $fileSystemService,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        ) extends AbstractRenameCommand {
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
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Performing dry run', $tester->getDisplay());
    }

    #[Test]
    public function executeAllowsSkipDuplicatesWithoutExplicitTarget(): void
    {
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $renameStrategy = $this->createStub(RenameStrategyInterface::class);
        $duplicateIdentifierStrategy = $this->createStub(DuplicateIdentifierStrategyInterface::class);

        $iteratorOne = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $iteratorTwo = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $fileDuplicateCollection = new FileDuplicateCollection();

        $fileSystemService
            ->expects(self::exactly(2))
            ->method('createFileIterator')
            ->with('/source-directory')
            ->willReturnOnConsecutiveCalls($iteratorOne, $iteratorTwo);

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
            ->method('setUseFileExtensionFromSource')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
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
            );

        $command = new class(
            $fileSystemService,
            $duplicateDetectionService,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        ) extends AbstractRenameCommand {
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
            '--dry-run' => true,
            '--skip-duplicates' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
